<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\PmsConnection;
use RuntimeException;

class ZohoApiClient
{
    public function __construct(
        private readonly ZohoRegionResolver $regions,
    ) {}

    public function baseRequest(string $url): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->baseUrl($url);
    }

    public function authenticatedRequest(PmsConnection $connection, string $url): PendingRequest
    {
        return $this->baseRequest($url)->withHeaders([
            'Authorization' => 'Zoho-oauthtoken '.$connection->access_token,
        ]);
    }

    public function fetchOrganizations(PmsConnection $connection): array
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->get('/organizations')
            ->throw()
            ->json();
    }

    public function fetchBill(PmsConnection $connection, string $billId, string $organizationId): array
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->get("/invoices/{$billId}", [
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }

    public function fetchContact(PmsConnection $connection, string $contactId, string $organizationId): array
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->get("/contacts/{$contactId}", [
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }

    public function createWebhook(PmsConnection $connection, string $organizationId, array $payload): array
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->post('/settings/webhooks', [
                ...$payload,
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }

    public function fetchWebhooks(PmsConnection $connection, string $organizationId): array
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->get('/settings/webhooks', [
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }

    public function updateWebhook(PmsConnection $connection, string $webhookId, string $organizationId, array $payload): array
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->put("/settings/webhooks/{$webhookId}", [
                ...$payload,
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }

    public function fetchWorkflows(PmsConnection $connection, string $organizationId): array
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->get('/settings/workflows', [
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }

    public function fetchWorkflow(PmsConnection $connection, string $workflowId, string $organizationId): array
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->get("/settings/workflows/{$workflowId}", [
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }

    public function updateWorkflow(PmsConnection $connection, string $workflowId, string $organizationId, array $payload): array
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->put("/settings/workflows/{$workflowId}", [
                ...$payload,
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }

    public function createWorkflow(PmsConnection $connection, string $organizationId, array $payload): array
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->post('/settings/workflows', [
                ...$payload,
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }

    public function recordInvoicePayment(
        PmsConnection $connection,
        string $organizationId,
        array $payload
    ): array {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->post('/customerpayments', [
                ...$payload,
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();
    }

    /** @return array<int, array> Books contacts whose email matches exactly (case-insensitive). */
    public function searchContactsByEmail(PmsConnection $connection, string $organizationId, string $email): array
    {
        $contacts = (array) ($this->booksRequest($connection, $organizationId)
            ->get('/contacts', ['email' => $email])
            ->throw()
            ->json('contacts') ?? []);

        return array_values(array_filter(
            $contacts,
            fn ($c) => is_array($c) && strcasecmp(trim((string) ($c['email'] ?? '')), $email) === 0
        ));
    }

    public function createContact(PmsConnection $connection, string $organizationId, array $payload): array
    {
        return $this->booksResult(
            $this->booksRequest($connection, $organizationId)->post('/contacts', $payload),
            'contact',
            'Zoho Books contact could not be created'
        );
    }

    public function findInvoiceByNumber(PmsConnection $connection, string $organizationId, string $invoiceNumber): ?array
    {
        $invoices = (array) ($this->booksRequest($connection, $organizationId)
            ->get('/invoices', ['invoice_number' => $invoiceNumber])
            ->throw()
            ->json('invoices') ?? []);

        foreach ($invoices as $invoice) {
            if (is_array($invoice) && (string) ($invoice['invoice_number'] ?? '') === $invoiceNumber) {
                return $invoice;
            }
        }

        return null;
    }

    /** Creates the invoice with our own invoice number instead of the org's auto-numbering. */
    public function createInvoice(PmsConnection $connection, string $organizationId, array $payload): array
    {
        return $this->booksResult(
            $this->booksRequest($connection, $organizationId)
                ->withQueryParameters(['ignore_auto_number_generation' => 'true'])
                ->post('/invoices', $payload),
            'invoice',
            'Zoho Books invoice could not be created'
        );
    }

    public function fetchInvoice(PmsConnection $connection, string $organizationId, string $invoiceId): array
    {
        return $this->booksResult(
            $this->booksRequest($connection, $organizationId)->get('/invoices/'.rawurlencode($invoiceId)),
            'invoice',
            'Zoho Books invoice could not be loaded'
        );
    }

    /** A draft invoice can't take a payment. Marking it sent sends no email. */
    public function markInvoiceSent(PmsConnection $connection, string $organizationId, string $invoiceId): void
    {
        $this->booksResult(
            $this->booksRequest($connection, $organizationId)->post('/invoices/'.rawurlencode($invoiceId).'/status/sent'),
            null,
            'Zoho Books invoice could not be marked as sent'
        );
    }

    /** @return array<int, array{item_id: string, name: string, rate: float}> active items, by name */
    public function fetchItems(PmsConnection $connection, string $organizationId): array
    {
        $items = (array) ($this->booksRequest($connection, $organizationId)
            ->get('/items', ['filter_by' => 'Status.Active'])
            ->throw()
            ->json('items') ?? []);

        return collect($items)
            ->filter(fn ($item) => is_array($item) && ! empty($item['item_id']))
            ->map(fn (array $item): array => [
                'item_id' => (string) $item['item_id'],
                'name' => (string) ($item['name'] ?? $item['item_name'] ?? 'Unnamed item'),
                'rate' => (float) ($item['rate'] ?? 0),
            ])
            ->sortBy(fn (array $item) => strtolower($item['name']))
            ->values()
            ->all();
    }

    public function fetchPaymentAccounts(PmsConnection $connection, string $organizationId): array
    {
        $response = $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->get('/chartofaccounts', [
                'organization_id' => $organizationId,
            ])
            ->throw()
            ->json();

        $accounts = $response['chartofaccounts'] ?? [];
        if (! is_array($accounts)) {
            return [];
        }

        return collect($accounts)
            ->filter(fn ($account) => is_array($account))
            ->map(fn (array $account): array => [
                'account_id' => (string) ($account['account_id'] ?? ''),
                'account_name' => (string) ($account['account_name'] ?? 'Unknown account'),
                'account_type' => (string) ($account['account_type'] ?? ''),
            ])
            ->filter(fn (array $account): bool => $account['account_id'] !== '')
            ->sortBy(fn (array $account) => strtolower($account['account_name']))
            ->values()
            ->all();
    }

    /** Books expects organization_id on the query string for every call. */
    private function booksRequest(PmsConnection $connection, string $organizationId): PendingRequest
    {
        return $this->authenticatedRequest($connection, $this->regions->booksApiBaseUrlForConnection($connection))
            ->timeout(20)
            ->withQueryParameters(['organization_id' => $organizationId]);
    }

    /** Zoho Books answers {"code": 0, ...} on success and a non-zero code with a message otherwise. */
    private function booksResult(Response $response, ?string $key, string $message): array
    {
        $code = $response->json('code');

        if ($response->successful() && (int) $code === 0) {
            return $key === null ? (array) $response->json() : (array) ($response->json($key) ?? []);
        }

        $detail = trim(implode(' ', array_filter([
            $code !== null ? "code {$code}" : null,
            $response->json('message'),
        ]))) ?: 'HTTP '.$response->status();

        throw new RuntimeException("{$message}: {$detail}");
    }
}
