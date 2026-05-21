<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\WaveConnection;
use RuntimeException;

class WaveApiClient
{
    public function graphqlRequest(?WaveConnection $connection = null): PendingRequest
    {
        $token = config('services.wave.full_access_token')
            ?: $connection?->access_token;

        if (! $token) {
            throw new RuntimeException('No Wave API token available. Set WAVE_FULL_ACCESS_TOKEN in .env.');
        }

        return Http::withToken($token)
            ->acceptJson()
            ->baseUrl(config('services.wave.graphql_url', 'https://gql.waveapps.com/graphql/public'));
    }

    public function fetchBusinessId(WaveConnection $connection): string
    {
        $cached = Arr::get($connection->meta ?? [], 'business_id');

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->graphqlRequest($connection)
            ->post('', [
                'query' => '{ businesses { edges { node { id name } } } }',
            ])
            ->throw();

        $edges = Arr::get($response->json(), 'data.businesses.edges', []);

        if (empty($edges)) {
            throw new RuntimeException('No Wave business found for this connection.');
        }

        $businessId = (string) Arr::get($edges[0], 'node.id', '');

        if ($businessId === '') {
            throw new RuntimeException('Wave API returned a business with no ID.');
        }

        $connection->forceFill(['meta' => array_merge($connection->meta ?? [], ['business_id' => $businessId])])->save();

        return $businessId;
    }

    public function registerWebhookSubscription(WaveConnection $connection, string $webhookUrl): array
    {
        $businessId = $this->fetchBusinessId($connection);

        $mutation = 'mutation ($input: WebhookCreateInput!) { webhookCreate(input: $input) { didSucceed errors { code message } webhook { id } } }';

        $registeredIds = [];

        foreach (['INVOICE_APPROVED', 'INVOICE_PAID'] as $event) {
            $response = $this->graphqlRequest($connection)
                ->post('', [
                    'query'     => $mutation,
                    'variables' => [
                        'input' => [
                            'businessId' => $businessId,
                            'event'      => $event,
                            'url'        => $webhookUrl,
                        ],
                    ],
                ])
                ->throw();

            $result = Arr::get($response->json(), 'data.webhookCreate');

            if (! Arr::get($result, 'didSucceed')) {
                $errors = collect(Arr::get($result, 'errors', []))->pluck('message')->implode('; ');
                throw new RuntimeException("Wave webhook registration failed for {$event}: " . ($errors ?: 'unknown error'));
            }

            $registeredIds[$event] = (string) Arr::get($result, 'webhook.id', '');
        }

        return $registeredIds;
    }

    public function fetchInvoice(WaveConnection $connection, string $invoiceId): array
    {
        $businessId = $this->fetchBusinessId($connection);

        $query = <<<'GQL'
        query GetInvoice($businessId: ID!, $invoiceId: ID!) {
            business(id: $businessId) {
                invoice(id: $invoiceId) {
                    id
                    invoiceNumber
                    title
                    status
                    amountDue { value currency { code } }
                    amountPaid { value currency { code } }
                    total { value currency { code } }
                    subTotal { value currency { code } }
                    customer { id name email }
                    dueDate
                    invoiceDate
                    memo
                }
            }
        }
        GQL;

        $response = $this->graphqlRequest($connection)
            ->post('', [
                'query'     => $query,
                'variables' => ['businessId' => $businessId, 'invoiceId' => $invoiceId],
            ])
            ->throw();

        $invoice = Arr::get($response->json(), 'data.business.invoice');

        if (! $invoice) {
            throw new RuntimeException("Wave invoice [{$invoiceId}] not found.");
        }

        return $invoice;
    }
}
