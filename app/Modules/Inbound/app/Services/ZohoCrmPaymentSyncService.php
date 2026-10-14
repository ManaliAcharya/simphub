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
 * The three custom fields are created on the Contacts module at connect time (or
 * found by label if the client created them); their API names live in the
 * connection meta under contact_fields.
 */
class ZohoCrmPaymentSyncService
{
    public const FIELDS = [
        'simphub_id' => ['field_label' => 'SimpHub ID', 'data_type' => 'text', 'length' => 255],
        'payment_link' => ['field_label' => 'SimpHub Payment Link', 'data_type' => 'website'],
        'last_payment_date' => ['field_label' => 'SimpHub Last Payment Date', 'data_type' => 'date'],
    ];

    public function __construct(
        private readonly ZohoCrmOAuthService $oauth,
        private readonly ZohoCrmApiClient $api,
        private readonly PaymentLinkService $paymentLinks,
    ) {}

    /**
     * Finds or creates the custom Contact fields and stores their API names.
     * $recheck ignores the stored names (used on connect/reconnect).
     *
     * @return array<string, string> field key => Zoho API name
     */
    public function ensureContactFields(PmsConnection $connection, bool $recheck = false): array
    {
        $stored = (array) data_get($connection->meta, 'contact_fields', []);
        $configured = $this->configuredFields();

        // Every field mapped in config (e.g. existing Contact fields while testing):
        // use them as-is, nothing is looked up or created in Zoho.
        if (count($configured) === count(self::FIELDS)) {
            if ($stored !== $configured || data_get($connection->meta, 'contact_fields_setup') !== 'success') {
                $this->saveMeta($connection, [
                    'contact_fields' => $configured,
                    'contact_fields_setup' => 'success',
                    'contact_fields_setup_error' => null,
                ]);
            }

            return $configured;
        }

        if (! $recheck && count(array_filter($stored)) === count(self::FIELDS)) {
            return $stored;
        }

        try {
            $connection = $this->oauth->ensureValidAccessToken($connection);
            $map = $configured + $this->matchFields($this->api->fetchContactFields($connection));
            $missing = array_diff_key(self::FIELDS, $map);

            foreach ($missing as $definition) {
                $this->api->createContactField($connection, $definition);
            }

            if ($missing !== []) {
                $map = $configured + $this->matchFields($this->api->fetchContactFields($connection));
            }

            if ($stillMissing = array_diff_key(self::FIELDS, $map)) {
                throw new RuntimeException('Zoho CRM fields not found after creating them: '
                    .implode(', ', array_column($stillMissing, 'field_label')).'.');
            }

            $this->saveMeta($connection, [
                'contact_fields' => $map,
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
            $contactId = $this->resolveContactId($connection, $subscription);

            $session = PaymentSession::query()
                ->where('invoice_id', $invoice->id)
                ->latest('created_at')
                ->first();

            $this->api->updateContact($connection, $contactId, array_filter([
                $fields['simphub_id'] => $subscription->reference_id,
                $fields['payment_link'] => $session ? $this->paymentLinks->urlForSession($session) : null,
                $fields['last_payment_date'] => ($transaction->created_at ?? now())->toDateString(),
            ]));

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

    /** @return array<string, string> field key => API name set in services.zohocrm.contact_fields */
    private function configuredFields(): array
    {
        $configured = array_map(
            fn ($apiName) => is_string($apiName) ? trim($apiName) : '',
            array_intersect_key((array) config('services.zohocrm.contact_fields', []), self::FIELDS)
        );

        return array_filter($configured, fn (string $apiName) => $apiName !== '');
    }

    private function saveMeta(PmsConnection $connection, array $values): void
    {
        $connection->forceFill(['meta' => [...((array) $connection->meta), ...$values]])->save();
    }
}
