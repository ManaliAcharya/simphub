<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\Log;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentSession;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Models\Subscription;
use Modules\Payment\Services\PaymentLinkService;
use RuntimeException;
use Throwable;

/**
 * After a subscription payment, writes this month's payment link, the payment date
 * and the SimpHub subscription id onto the customer's Contact in Zoho CRM.
 *
 * By default three custom fields are created on the Contacts module at connect time
 * (or found by label if the client created them). The client can instead map each
 * value to any compatible Contact field on the config page, or choose not to sync it.
 * The API names live in the connection meta under contact_fields, their Zoho data
 * types under contact_field_types, and contact_fields_mapping is 'manual' once the
 * client has saved a mapping (reconnecting then keeps it).
 */
class ZohoCrmPaymentSyncService
{
    /** Field definitions sent to Zoho when creating the default SimpHub fields. */
    public const FIELDS = [
        'simphub_id' => ['field_label' => 'SimpHub ID', 'data_type' => 'text', 'length' => 255],
        'payment_link' => ['field_label' => 'SimpHub Payment Link', 'data_type' => 'website'],
        'last_payment_date' => ['field_label' => 'SimpHub Last Payment Date', 'data_type' => 'date'],
    ];

    /** What each value is, and the Zoho field types it can be written to. */
    public const VALUES = [
        'simphub_id' => [
            'label' => 'SimpHub ID',
            'description' => 'Subscription reference, e.g. SH-SUB-000123',
            'types' => ['text', 'textarea'],
        ],
        'payment_link' => [
            'label' => 'Payment Link',
            'description' => 'Payment page link for this month\'s invoice',
            'types' => ['website', 'text', 'textarea'],
        ],
        'last_payment_date' => [
            'label' => 'Last Payment Date',
            'description' => 'Date of the latest subscription payment',
            'types' => ['date', 'datetime', 'text'],
        ],
    ];

    public const MAPPING_MANUAL = 'manual';

    public function __construct(
        private readonly ZohoCrmOAuthService $oauth,
        private readonly ZohoCrmApiClient $api,
        private readonly PaymentLinkService $paymentLinks,
    ) {}

    /**
     * Finds or creates the custom Contact fields and stores their API names.
     * $recheck ignores the stored names (used on connect/reconnect). A mapping the
     * client saved on the config page is always used as-is.
     *
     * @return array<string, string> field key => Zoho API name
     */
    public function ensureContactFields(PmsConnection $connection, bool $recheck = false): array
    {
        $stored = array_filter((array) data_get($connection->meta, 'contact_fields', []));

        if ($this->isManuallyMapped($connection)) {
            return $stored;
        }

        if (! $recheck && count($stored) === count(self::FIELDS)) {
            return $stored;
        }

        try {
            $connection = $this->oauth->ensureValidAccessToken($connection);
            $map = $this->matchFields($this->api->fetchContactFields($connection));
            $missing = array_diff_key(self::FIELDS, $map);

            foreach ($missing as $definition) {
                $this->api->createContactField($connection, $definition);
            }

            if ($missing !== []) {
                $map = $this->matchFields($this->api->fetchContactFields($connection));
            }

            if ($stillMissing = array_diff_key(self::FIELDS, $map)) {
                throw new RuntimeException('Zoho CRM fields not found after creating them: '
                    .implode(', ', array_column($stillMissing, 'field_label')).'.');
            }

            $this->saveMeta($connection, [
                'contact_fields' => $map,
                'contact_field_types' => array_map(fn (array $field) => $field['data_type'], self::FIELDS),
                'contact_fields_setup' => 'success',
                'contact_fields_setup_error' => null,
            ]);

            return $map;
        } catch (Throwable $e) {
            $this->saveMeta($connection, [
                'contact_fields_setup' => 'failed',
                'contact_fields_setup_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function isManuallyMapped(PmsConnection $connection): bool
    {
        return data_get($connection->meta, 'contact_fields_mapping') === self::MAPPING_MANUAL;
    }

    /**
     * The writable Contact fields each value can be mapped to, for the config page dropdowns.
     *
     * @return array<string, array<int, array{api_name: string, label: string, type: string}>> value key => options
     */
    public function mappableFields(PmsConnection $connection): array
    {
        $connection = $this->oauth->ensureValidAccessToken($connection);
        $fields = [];

        foreach ($this->api->fetchContactFields($connection) as $field) {
            $apiName = (string) ($field['api_name'] ?? '');

            if ($apiName === '' || ! empty($field['read_only']) || ! empty($field['field_read_only'])) {
                continue;
            }

            $fields[] = [
                'api_name' => $apiName,
                'label' => (string) ($field['field_label'] ?? $field['display_label'] ?? $apiName),
                'type' => (string) ($field['data_type'] ?? ''),
            ];
        }

        usort($fields, fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));

        return array_map(
            fn (array $value) => array_values(array_filter($fields, fn (array $f) => in_array($f['type'], $value['types'], true))),
            self::VALUES
        );
    }

    /**
     * Saves the client's mapping. Each selection must be a compatible Contact field
     * (checked against Zoho) or empty for "don't sync"; one field can't take two values.
     *
     * @param  array<string, ?string>  $selection  value key => Zoho API name
     * @return array<string, string> the saved mapping
     */
    public function saveMapping(PmsConnection $connection, array $selection): array
    {
        $options = $this->mappableFields($connection);
        $map = [];
        $types = [];

        foreach (self::VALUES as $key => $value) {
            $apiName = trim((string) ($selection[$key] ?? ''));

            if ($apiName === '') {
                continue;
            }

            $field = collect($options[$key])->firstWhere('api_name', $apiName);

            if (! $field) {
                throw new RuntimeException("\"{$apiName}\" is not a Contact field that can hold the {$value['label']}. Choose one from the list.");
            }

            $map[$key] = $apiName;
            $types[$key] = $field['type'];
        }

        if (count($map) !== count(array_unique($map))) {
            throw new RuntimeException('Each Zoho CRM field can only be mapped to one SimpHub value.');
        }

        $this->saveMeta($connection->fresh(), [
            'contact_fields' => $map,
            'contact_field_types' => $types,
            'contact_fields_mapping' => self::MAPPING_MANUAL,
            'contact_fields_setup' => 'success',
            'contact_fields_setup_error' => null,
        ]);

        return $map;
    }

    /**
     * @return bool true when the Contact was updated
     */
    public function sync(Transaction $transaction, Invoice $invoice): bool
    {
        $subscriptionId = data_get($invoice->metadata, 'subscription_id');

        $connection = PmsConnection::query()
            ->where('provider', ZohoCrmOAuthService::PROVIDER)
            ->where('pms_client_id', $invoice->pms_client_id)
            ->latest('created_at')
            ->first();

        // Connecting Zoho CRM is optional: API-only clients get nothing written back.
        if (! $subscriptionId || ! $connection) {
            return false;
        }

        try {
            $subscription = Subscription::query()->findOrFail($subscriptionId);
            $connection = $this->oauth->ensureValidAccessToken($connection);
            $fields = $this->ensureContactFields($connection);

            // The client chose not to sync any value.
            if ($fields === []) {
                return false;
            }

            $contactId = $this->resolveContactId($connection, $subscription);

            $session = PaymentSession::query()
                ->where('invoice_id', $invoice->id)
                ->latest('created_at')
                ->first();

            $paidAt = $transaction->created_at ?? now();
            $values = [
                'simphub_id' => $subscription->reference_id,
                'payment_link' => $session ? $this->paymentLinks->urlForSession($session) : null,
                'last_payment_date' => data_get($connection->meta, 'contact_field_types.last_payment_date') === 'datetime'
                    ? $paidAt->toIso8601String()
                    : $paidAt->toDateString(),
            ];

            $data = [];

            foreach ($fields as $key => $apiName) {
                if (($values[$key] ?? null) !== null) {
                    $data[$apiName] = $values[$key];
                }
            }

            if ($data !== []) {
                $this->api->updateContact($connection, $contactId, $data);
            }

            $invoice->forceFill(['pms_sync_status' => 'SYNCED'])->save();

            AuditLogger::log('PMS_PAYMENT_RECORDED', 'invoice', $invoice->id, [
                'pms_source' => ZohoCrmOAuthService::PROVIDER,
                'transaction_id' => $transaction->id,
                'subscription_reference_id' => $subscription->reference_id,
                'zoho_contact_id' => $contactId,
            ]);

            return true;
        } catch (Throwable $e) {
            $invoice->forceFill(['pms_sync_status' => 'FAILED'])->save();

            AuditLogger::log('PMS_PAYMENT_RECORD_FAILED', 'invoice', $invoice->id, [
                'pms_source' => ZohoCrmOAuthService::PROVIDER,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            Log::warning('Zoho CRM contact update failed', [
                'invoice_id' => $invoice->id,
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * customer_unique_id is used when it is a Zoho Contact record id; otherwise the
     * Contact is found by email (and name, if several Contacts share the email).
     */
    private function resolveContactId(PmsConnection $connection, Subscription $subscription): string
    {
        $uniqueId = (string) $subscription->customer_unique_id;

        if (ctype_digit($uniqueId) && ($contact = $this->api->fetchContact($connection, $uniqueId))) {
            return (string) $contact['id'];
        }

        $contacts = $this->api->searchContactsByEmail($connection, $subscription->customer_email);

        if (count($contacts) > 1 && $subscription->customer_name) {
            $name = mb_strtolower(trim($subscription->customer_name));
            $contacts = array_values(array_filter(
                $contacts,
                fn (array $c) => mb_strtolower(trim((string) ($c['Full_Name'] ?? ''))) === $name
            ));
        }

        return match (count($contacts)) {
            1 => (string) $contacts[0]['id'],
            0 => throw new RuntimeException("No Zoho CRM contact found for {$subscription->customer_email}."),
            default => throw new RuntimeException(
                "More than one Zoho CRM contact matches {$subscription->customer_email}. "
                .'Send the Zoho Contact ID as customer_unique_id when subscribing.'
            ),
        };
    }

    /** @return array<string, string> field key => API name, for the fields that exist */
    private function matchFields(array $zohoFields): array
    {
        $byLabel = [];

        foreach ($zohoFields as $field) {
            $label = mb_strtolower(trim((string) ($field['field_label'] ?? $field['display_label'] ?? '')));

            if ($label !== '' && ! empty($field['api_name'])) {
                $byLabel[$label] = (string) $field['api_name'];
            }
        }

        $map = [];

        foreach (self::FIELDS as $key => $definition) {
            if ($apiName = $byLabel[mb_strtolower($definition['field_label'])] ?? null) {
                $map[$key] = $apiName;
            }
        }

        return $map;
    }

    private function saveMeta(PmsConnection $connection, array $values): void
    {
        $connection->forceFill(['meta' => [...((array) $connection->meta), ...$values]])->save();
    }
}
