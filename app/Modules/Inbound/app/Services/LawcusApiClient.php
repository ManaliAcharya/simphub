<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Inbound\Models\LawcusConnection;
use RuntimeException;

class LawcusApiClient
{
    public function baseRequest(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->baseUrl(config('services.lawcus.api_base_url'));
    }

    public function authenticatedRequest(LawcusConnection $connection): PendingRequest
    {
        return $this->baseRequest()->withToken($connection->access_token);
    }

    public function postWebhook(LawcusConnection $connection, array $payload): Response
    {
        return $this->authenticatedRequest($connection)->post('/api/v1/webhooks', $payload);
    }

    /**
     * GET /invoices/{uuid} on the REST host (undocumented, but the only per-invoice
     * endpoint that accepts pasted API tokens). Lawcus answers an unknown id with 200
     * and an empty "invoice" — treated as not found rather than an empty invoice.
     *
     * Returned as {data: invoice, payments: [...]} so the ingestion service's
     * normalizer reads it the same way as other PMS payloads.
     */
    public function fetchBill(LawcusConnection $connection, string $externalInvoiceId): array
    {
        $payload = $this->apiTokenRequest($connection)
            ->get('/invoices/'.rawurlencode($externalInvoiceId))
            ->throw()
            ->json();

        $invoice = Arr::get((array) $payload, 'invoice');

        if (! is_array($invoice) || $invoice === []) {
            throw new RuntimeException("Lawcus invoice [{$externalInvoiceId}] was not found.");
        }

        return [
            'data'     => $invoice,
            'payments' => (array) Arr::get($payload, 'payments', []),
        ];
    }

    /**
     * Contacts are looked up by UUID on the REST host — the numeric contact id that
     * invoices carry as client_id returns 404 here.
     */
    public function fetchContact(LawcusConnection $connection, string $contactUuid): array
    {
        return (array) $this->apiTokenRequest($connection)
            ->get('/contacts/'.rawurlencode($contactUuid))
            ->throw()
            ->json();
    }

    /**
     * Sent, unpaid invoices (Accounts Receivable report). There's no working invoice
     * list endpoint — GET /invoices returns 500 — and the report is the documented way
     * to enumerate invoices. Drafts/approved-but-unsent invoices are not included.
     *
     * @return array<int, array{id: string, number: string, amount_due: string, status: string, issue_date: string, client_id: int}>
     */
    public function fetchOutstandingInvoices(LawcusConnection $connection): array
    {
        $payload = $this->apiTokenRequest($connection)
            ->get('/reports/accounts-receivable', [
                'start' => '2000-01-01',
                'end'   => now()->addDay()->toDateString(),
            ])
            ->throw()
            ->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Lawcus returned an unexpected (non-JSON) accounts-receivable response.');
        }

        // Grouped by client id: { "<client_id>": [ {invoice}, ... ], ... }
        return collect($payload)
            ->filter(fn ($group) => is_array($group))
            ->flatten(1)
            ->filter(fn ($invoice) => is_array($invoice) && ! empty($invoice['id']))
            ->values()
            ->all();
    }

    /**
     * Pasted Lawcus API tokens are only accepted on the public REST host
     * (api.us/api.eu) and only via the LAWCUS-X-API-KEY header — Bearer is
     * rejected. The token works across regions, so the US host is used.
     */
    public function apiTokenRequest(LawcusConnection $connection): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->baseUrl(config('services.lawcus.rest_api_base_url'))
            ->withHeaders(['LAWCUS-X-API-KEY' => trim((string) $connection->access_token)]);
    }

    public function fetchBankAccounts(LawcusConnection $connection): array
    {
        $response = $this->apiTokenRequest($connection)
            ->get('/accounts')
            ->throw();

        $payload = $response->json();

        // A non-JSON 200 (e.g. an HTML page from the wrong host) must surface as an
        // error rather than silently rendering as "no accounts".
        if (! is_array($payload)) {
            throw new RuntimeException('Lawcus returned an unexpected (non-JSON) response when listing accounts.');
        }

        $accounts = $payload['data'] ?? $payload;

        return collect($accounts)
            ->filter(fn ($account) => is_array($account))
            ->map(fn (array $account): array => [
                'account_id'   => (string) ($account['id']   ?? ''),
                'account_name' => (string) ($account['name'] ?? 'Unknown'),
                'account_type' => (string) ($account['account_type'] ?? $account['type'] ?? ''),
            ])
            ->filter(fn (array $account): bool => $account['account_id'] !== '')
            ->sortBy(fn (array $account) => strtolower($account['account_name']))
            ->values()
            ->all();
    }

    /**
     * Records one payment against an invoice — same request the Lawcus web app's
     * "Record payment" dialog sends (undocumented; the documented API has no payment
     * endpoint). Note POST /payments is Lawcus' own subscription billing, not this.
     *
     * The body is {payments: [...]} at the top level — wrapping it in "data" gets a
     * 200 with nothing created. Lawcus answers [] either way, so callers must confirm
     * the payment by re-reading the invoice.
     */
    public function recordInvoicePayment(LawcusConnection $connection, array $payment): array
    {
        return (array) $this->apiTokenRequest($connection)
            ->post('/accounts/transactions', ['payments' => [$payment]])
            ->throw()
            ->json();
    }
}
