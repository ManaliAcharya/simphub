<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\WaveConnection;
use RuntimeException;

class WaveApiClient
{
    private function graphqlEndpoint(): string
    {
        return rtrim(config('services.wave.graphql_url', 'https://gql.waveapps.com/graphql/public'), '/');
    }

    private function graphqlToken(?WaveConnection $connection = null): string
    {
        $token = config('services.wave.full_access_token') ?: $connection?->access_token;

        if (! $token) {
            throw new RuntimeException('No Wave API token available. Set WAVE_FULL_ACCESS_TOKEN in .env.');
        }

        return $token;
    }

    private function graphqlPost(?WaveConnection $connection, array $body, ?string $tokenOverride = null): array
    {
        $token = $tokenOverride ?? $this->graphqlToken($connection);

        return Http::withToken($token)
            ->acceptJson()
            ->post($this->graphqlEndpoint(), $body)
            ->throw()
            ->json();
    }

    public function fetchBusinessId(WaveConnection $connection): string
    {
        $cached = Arr::get($connection->meta ?? [], 'business_id');

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // Must use the connection's OAuth token — Full Access Token only sees the developer's own business
        $data = $this->graphqlPost($connection, [
            'query' => '{ businesses(page: 1, pageSize: 10) { edges { node { id name } } } }',
        ], $connection->access_token);

        $edges = Arr::get($data, 'data.businesses.edges', []);

        if (empty($edges)) {
            throw new RuntimeException('No Wave business found for this connection.');
        }

        $graphqlId = (string) Arr::get($edges[0], 'node.id', '');

        if ($graphqlId === '') {
            throw new RuntimeException('Wave API returned a business with no ID.');
        }

        // GraphQL returns base64 global IDs like "Business:7c6e10e4-..."
        // Webhook payload sends the plain UUID — decode and strip the type prefix
        $decoded    = base64_decode($graphqlId);
        $businessId = str_contains($decoded, ':')
            ? substr($decoded, strrpos($decoded, ':') + 1)
            : $graphqlId;

        $connection->forceFill(['meta' => array_merge($connection->meta ?? [], ['business_id' => $businessId])])->save();

        logger()->info('Wave: stored business_id in meta', [
            'pms_client_id' => $connection->pms_client_id,
            'graphql_id'    => $graphqlId,
            'decoded'       => $decoded,
            'business_id'   => $businessId,
        ]);

        return $businessId;
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

        $data = $this->graphqlPost($connection, [
            'query'     => $query,
            'variables' => ['businessId' => $businessId, 'invoiceId' => $invoiceId],
        ], $connection->access_token);

        $invoice = Arr::get($data, 'data.business.invoice');

        if (! $invoice) {
            throw new RuntimeException("Wave invoice [{$invoiceId}] not found.");
        }

        return $invoice;
    }
}
