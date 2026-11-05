<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Audit\Services\AuditLogger;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Transaction;
use Modules\Inbound\Jobs\SyncZohoBooksPaymentJob;
use Modules\Inbound\Models\PmsConnection;
use Modules\Inbound\Models\Subscription;
use Modules\Payment\Listeners\SyncInvoicePaidListener;
use RuntimeException;
use Throwable;

/**
 * After a Zoho CRM subscription payment, creates the period's invoice in Zoho Books
 * and records the payment against it.
 *
 * Books shares the client's Zoho CRM connection (pms_connections holds one row per
 * client): the CRM OAuth scopes include Books, and the Books settings live in the
 * connection meta under "books". Off until the client turns it on.
 *
 * Every step saves its Zoho ID before the next one starts (customer on the
 * subscription, invoice and payment in invoice metadata.zoho_books), and the invoice
 * and payment are looked up in Books before being created, so a retry never makes a
 * second invoice or records the payment twice.
 */
class ZohoBooksSubscriptionSyncService
{
    public const STATUS_QUEUED          = 'queued';
    public const STATUS_CUSTOMER_READY  = 'customer_ready';
    public const STATUS_INVOICE_CREATED = 'invoice_created';
    public const STATUS_SYNCED          = 'synced';
    public const STATUS_FAILED          = 'failed';

    /** Chart of accounts types a payment can be deposited to. */
    private const DEPOSIT_ACCOUNT_TYPES = ['bank', 'cash', 'payment_clearing', 'other_current_asset'];

    public function __construct(
        private readonly ZohoCrmOAuthService $oauth,
        private readonly ZohoApiClient $books,
    ) {}

    public function connectionFor(string $pmsClientId): ?PmsConnection
    {
        return PmsConnection::query()
            ->where('provider', ZohoCrmOAuthService::PROVIDER)
            ->where('pms_client_id', $pmsClientId)
            ->latest('created_at')
            ->first();
    }

    /** @return array{enabled: bool, organization_id: ?string, organization_name: ?string, account_id: ?string, account_name: ?string, item_id: ?string, item_name: ?string} */
    public function settings(?PmsConnection $connection): array
    {
        $books = (array) data_get($connection?->meta, 'books', []);

        return [
            'enabled'           => (bool) ($books['enabled'] ?? false),
            'organization_id'   => $books['organization_id'] ?? null,
            'organization_name' => $books['organization_name'] ?? null,
            'account_id'        => $books['account_id'] ?? null,
            'account_name'      => $books['account_name'] ?? null,
            'item_id'           => $books['item_id'] ?? null,
            'item_name'         => $books['item_name'] ?? null,
        ];
    }

    public function isEnabled(?PmsConnection $connection): bool
    {
        $settings = $this->settings($connection);

        return $settings['enabled'] && filled($settings['organization_id']);
    }

    /**
     * True when a paid subscription invoice should get a Books sync job: Books sync is on
     * for the client and this invoice isn't in Books yet.
     */
    public function shouldSync(Invoice $invoice): bool
    {
        return strtolower((string) $invoice->pms_source) === SubscriptionService::PMS_SOURCE
            && data_get($invoice->metadata, 'subscription_id')
            && data_get($invoice->metadata, 'zoho_books.status') !== self::STATUS_SYNCED
            && $this->isEnabled($this->connectionFor((string) $invoice->pms_client_id));
    }

    /** The payment a retry records: the invoice's latest approved transaction. */
    public function approvedTransaction(Invoice $invoice): ?Transaction
    {
        return Transaction::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', 'APPROVED')
            ->latest('created_at')
            ->first();
    }

    /** Queues a retry for each paid invoice whose Books sync failed. @return int invoices queued */
    public function queueFailed(?string $pmsClientId = null): int
    {
        $queued = 0;

        Invoice::query()
            ->where('pms_source', SubscriptionService::PMS_SOURCE)
            ->where('status', 'PAID')
            ->where('metadata->zoho_books->status', self::STATUS_FAILED)
            ->when($pmsClientId, fn ($q) => $q->where('pms_client_id', $pmsClientId))
            ->chunkById(200, function ($invoices) use (&$queued): void {
                foreach ($invoices as $invoice) {
                    if (! $transaction = $this->approvedTransaction($invoice)) {
                        continue;
                    }

                    try {
                        SyncZohoBooksPaymentJob::dispatch($invoice->id, $transaction->id);
                    } catch (Throwable) {
                        // Sync queue driver: the job ran inline and recorded its failure.
                    }

                    $queued++;
                }
            });

        return $queued;
    }

    public function markQueued(Invoice $invoice): void
    {
        if (data_get($invoice->metadata, 'zoho_books.status') === null) {
            $this->saveState($invoice, ['status' => self::STATUS_QUEUED]);
        }
    }

    /**
     * Runs (or resumes) the sync. Records the failure on the invoice and rethrows,
     * so the queued job retries.
     *
     * @return bool true when the invoice is in Books and paid
     */
    public function sync(Invoice $invoice, Transaction $transaction): bool
    {
        $invoice = $invoice->fresh();
        $connection = $this->connectionFor((string) $invoice->pms_client_id);

        if (! $this->shouldSync($invoice)) {
            return data_get($invoice->metadata, 'zoho_books.status') === self::STATUS_SYNCED;
        }

        $settings = $this->settings($connection);
        $orgId = (string) $settings['organization_id'];

        try {
            $connection = $this->oauth->ensureValidAccessToken($connection);
            $subscription = Subscription::query()->findOrFail(data_get($invoice->metadata, 'subscription_id'));

            $customerId = $this->resolveCustomer($connection, $orgId, $subscription);
            $this->saveState($invoice, ['status' => self::STATUS_CUSTOMER_READY, 'customer_id' => $customerId]);

            $booksInvoice = $this->resolveInvoice($connection, $orgId, $invoice, $customerId, $settings);
            $this->saveState($invoice, ['status' => self::STATUS_INVOICE_CREATED, 'invoice_id' => $booksInvoice['invoice_id']]);

            if (strtolower((string) ($booksInvoice['status'] ?? '')) === 'draft') {
                $this->books->markInvoiceSent($connection, $orgId, $booksInvoice['invoice_id']);
            }

            $paymentId = $this->recordPayment($connection, $orgId, $invoice, $transaction, $customerId, $booksInvoice['invoice_id'], $settings);

            $this->saveState($invoice, [
                'status'     => self::STATUS_SYNCED,
                'payment_id' => $paymentId,
                'synced_at'  => now()->toIso8601String(),
                'error'      => null,
            ]);

            AuditLogger::log('ZOHO_BOOKS_PAYMENT_RECORDED', 'invoice', $invoice->id, [
                'transaction_id'           => $transaction->id,
                'subscription_reference_id' => $subscription->reference_id,
                'organization_id'          => $orgId,
                'zoho_books_customer_id'   => $customerId,
                'zoho_books_invoice_id'    => $booksInvoice['invoice_id'],
                'zoho_books_payment_id'    => $paymentId,
            ]);

            return true;
        } catch (Throwable $e) {
            $attempts = (int) data_get($invoice->fresh()->metadata, 'zoho_books.attempts', 0) + 1;

            $this->saveState($invoice, [
                'status'    => self::STATUS_FAILED,
                'error'     => Str::limit($e->getMessage(), 500),
                'attempts'  => $attempts,
                'failed_at' => now()->toIso8601String(),
            ]);

            AuditLogger::log('ZOHO_BOOKS_SYNC_FAILED', 'invoice', $invoice->id, [
                'transaction_id' => $transaction->id,
                'attempt'        => $attempts,
                'error'          => $e->getMessage(),
            ]);

            Log::warning('Zoho Books subscription sync failed', [
                'invoice_id' => $invoice->id,
                'attempt'    => $attempts,
                'error'      => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Checks the connection can reach Zoho Books, and picks the organization when the
     * account has only one. Called after connecting.
     */
    public function checkAccess(PmsConnection $connection): void
    {
        try {
            $connection = $this->oauth->ensureValidAccessToken($connection);
            $organizations = $this->organizations($connection);
            $books = (array) data_get($connection->meta, 'books', []);

            if (empty($books['organization_id']) && count($organizations) === 1) {
                $books['organization_id'] = $organizations[0]['organization_id'];
                $books['organization_name'] = $organizations[0]['name'];
            }

            $this->saveBooksMeta($connection, [...$books, 'access' => 'granted', 'access_error' => null]);
        } catch (Throwable $e) {
            $this->saveBooksMeta($connection, [
                ...((array) data_get($connection->meta, 'books', [])),
                'access'       => 'missing',
                'access_error' => Str::limit($e->getMessage(), 500),
            ]);

            Log::warning('Zoho Books access check failed for a Zoho CRM connection', [
                'pms_client_id' => $connection->pms_client_id,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * Live lists for the settings form. Accounts and items are only loaded once an
     * organization is chosen.
     *
     * @return array{organizations: array, accounts: array, items: array}
     */
    public function options(PmsConnection $connection): array
    {
        $connection = $this->oauth->ensureValidAccessToken($connection);
        $orgId = $this->settings($connection)['organization_id'];

        $accounts = [];
        $items = [];

        if ($orgId) {
            $all = $this->books->fetchPaymentAccounts($connection, $orgId);
            $accounts = array_values(array_filter(
                $all,
                fn (array $a) => in_array(strtolower($a['account_type']), self::DEPOSIT_ACCOUNT_TYPES, true)
            )) ?: $all;
            $items = $this->books->fetchItems($connection, $orgId);
        }

        return [
            'organizations' => $this->organizations($connection),
            'accounts'      => $accounts,
            'items'         => $items,
        ];
    }

    /**
     * Saves the client's choices, each checked against the live Books lists. Changing
     * the organization clears the account and item, which belong to the old one.
     *
     * @param  array{enabled?: mixed, organization_id?: ?string, account_id?: ?string, item_id?: ?string}  $input
     * @return array the saved settings
     */
    public function saveSettings(PmsConnection $connection, array $input): array
    {
        $current = $this->settings($connection);
        $options = $this->options($connection);
        $orgId = trim((string) ($input['organization_id'] ?? ''));

        $organization = collect($options['organizations'])->firstWhere('organization_id', $orgId);

        if ($orgId !== '' && ! $organization) {
            throw new RuntimeException('Choose a Zoho Books organization from the list.');
        }

        $books = [
            ...((array) data_get($connection->meta, 'books', [])),
            'organization_id'   => $organization['organization_id'] ?? null,
            'organization_name' => $organization['name'] ?? null,
        ];

        if ($books['organization_id'] !== $current['organization_id']) {
            $books = [...$books, 'account_id' => null, 'account_name' => null, 'item_id' => null, 'item_name' => null];
        } else {
            $accountId = trim((string) ($input['account_id'] ?? ''));
            $itemId = trim((string) ($input['item_id'] ?? ''));
            $account = collect($options['accounts'])->firstWhere('account_id', $accountId);
            $item = collect($options['items'])->firstWhere('item_id', $itemId);

            if ($accountId !== '' && ! $account) {
                throw new RuntimeException('Choose a deposit account from the list.');
            }

            if ($itemId !== '' && ! $item) {
                throw new RuntimeException('Choose an item from the list.');
            }

            $books = [
                ...$books,
                'account_id'   => $account['account_id'] ?? null,
                'account_name' => $account['account_name'] ?? null,
                'item_id'      => $item['item_id'] ?? null,
                'item_name'    => $item['name'] ?? null,
            ];
        }

        $enabled = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($enabled && empty($books['organization_id'])) {
            throw new RuntimeException('Choose a Zoho Books organization before turning on Books invoices.');
        }

        $books['enabled'] = $enabled;
        $books['access'] = 'granted';
        $books['access_error'] = null;

        $this->saveBooksMeta($connection->fresh(), $books);

        return $this->settings($connection->fresh());
    }

    /** Saved Books ID, else the Books contact with the customer's email, else a new contact. */
    private function resolveCustomer(PmsConnection $connection, string $orgId, Subscription $subscription): string
    {
        if ($subscription->zoho_books_customer_id) {
            return (string) $subscription->zoho_books_customer_id;
        }

        $contacts = $this->books->searchContactsByEmail($connection, $orgId, $subscription->customer_email);

        if (count($contacts) > 1 && $subscription->customer_name) {
            $name = mb_strtolower(trim($subscription->customer_name));
            $byName = array_values(array_filter(
                $contacts,
                fn (array $c) => mb_strtolower(trim((string) ($c['contact_name'] ?? ''))) === $name
            ));
            $contacts = $byName ?: $contacts;
        }

        $customerId = match (count($contacts)) {
            0 => (string) data_get($this->books->createContact($connection, $orgId, [
                'contact_name'    => $subscription->customer_name ?: $subscription->customer_email,
                'contact_type'    => 'customer',
                'contact_persons' => [[
                    'email'              => $subscription->customer_email,
                    'is_primary_contact' => true,
                ]],
            ]), 'contact_id'),
            1 => (string) $contacts[0]['contact_id'],
            default => throw new RuntimeException(
                "More than one Zoho Books contact matches {$subscription->customer_email}. Merge them in Zoho Books, then retry."
            ),
        };

        if ($customerId === '') {
            throw new RuntimeException('Zoho Books did not return a contact ID.');
        }

        $subscription->forceFill(['zoho_books_customer_id' => $customerId])->save();

        return $customerId;
    }

    /**
     * The saved Books invoice, else one Books already has with this invoice number
     * (left by a run that stopped before saving), else a new one.
     *
     * @return array{invoice_id: string, status: ?string}
     */
    private function resolveInvoice(PmsConnection $connection, string $orgId, Invoice $invoice, string $customerId, array $settings): array
    {
        if ($savedId = data_get($invoice->fresh()->metadata, 'zoho_books.invoice_id')) {
            $found = $this->books->fetchInvoice($connection, $orgId, (string) $savedId);

            return ['invoice_id' => (string) $savedId, 'status' => $found['status'] ?? null];
        }

        $found = $this->books->findInvoiceByNumber($connection, $orgId, (string) $invoice->invoice_number);

        if (! $found) {
            $line = [
                'description' => (string) $invoice->description,
                'rate'        => round($invoice->amount_cents / 100, 2),
                'quantity'    => 1,
            ];

            $line += $settings['item_id']
                ? ['item_id' => $settings['item_id']]
                : ['name' => (string) $invoice->description];

            $found = $this->books->createInvoice($connection, $orgId, [
                'customer_id'      => $customerId,
                'invoice_number'   => (string) $invoice->invoice_number,
                'reference_number' => (string) data_get($invoice->metadata, 'subscription_reference_id', ''),
                'date'             => now()->toDateString(),
                'line_items'       => [$line],
            ]);
        }

        if (empty($found['invoice_id'])) {
            throw new RuntimeException('Zoho Books did not return an invoice ID.');
        }

        return ['invoice_id' => (string) $found['invoice_id'], 'status' => $found['status'] ?? null];
    }

    /**
     * Applies the invoice portion of the charge (never the fee). An invoice that Books
     * already shows as paid is not paid again.
     */
    private function recordPayment(
        PmsConnection $connection,
        string $orgId,
        Invoice $invoice,
        Transaction $transaction,
        string $customerId,
        string $booksInvoiceId,
        array $settings,
    ): ?string {
        if ($savedId = data_get($invoice->fresh()->metadata, 'zoho_books.payment_id')) {
            return (string) $savedId;
        }

        $booksInvoice = $this->books->fetchInvoice($connection, $orgId, $booksInvoiceId);
        $balance = (float) ($booksInvoice['balance'] ?? 0);

        if ($balance <= 0) {
            return data_get($booksInvoice, 'payments.0.payment_id');
        }

        $amount = min(SyncInvoicePaidListener::invoicePortion($transaction, $invoice), $balance);

        $payload = array_filter([
            'customer_id'      => $customerId,
            'payment_mode'     => 'Credit Card',
            'amount'           => $amount,
            'date'             => ($transaction->created_at ?? now())->toDateString(),
            'reference_number' => (string) $transaction->gateway_txn_id,
            'description'      => 'Subscription payment recorded by SimpHub',
            'account_id'       => $settings['account_id'],
            'invoices'         => [[
                'invoice_id'     => $booksInvoiceId,
                'amount_applied' => $amount,
            ]],
        ], fn ($value) => $value !== null && $value !== '');

        $response = $this->books->recordInvoicePayment($connection, $orgId, $payload);

        return data_get($response, 'payment.payment_id');
    }

    /** @return array<int, array{organization_id: string, name: string}> */
    private function organizations(PmsConnection $connection): array
    {
        return collect((array) ($this->books->fetchOrganizations($connection)['organizations'] ?? []))
            ->filter(fn ($org) => is_array($org) && ! empty($org['organization_id']))
            ->map(fn (array $org): array => [
                'organization_id' => (string) $org['organization_id'],
                'name'            => (string) ($org['name'] ?? $org['organization_id']),
            ])
            ->values()
            ->all();
    }

    private function saveState(Invoice $invoice, array $values): void
    {
        $fresh = $invoice->fresh();
        $metadata = (array) $fresh->metadata;
        $metadata['zoho_books'] = [...((array) ($metadata['zoho_books'] ?? [])), ...$values];

        $fresh->forceFill(['metadata' => $metadata])->save();
    }

    private function saveBooksMeta(PmsConnection $connection, array $books): void
    {
        $connection->forceFill(['meta' => [...((array) $connection->meta), 'books' => $books]])->save();
    }
}
