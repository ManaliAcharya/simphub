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
        $token    = $tokenOverride ?? $this->graphqlToken($connection);
        $response = Http::withToken($token)
            ->acceptJson()
            ->post($this->graphqlEndpoint(), $body);

        $json = $response->json() ?? [];

        if ($response->failed() || ! empty($json['errors'])) {
            logger()->error('Wave GraphQL error', [
                'status'  => $response->status(),
                'errors'  => $json['errors'] ?? [],
                'query'   => $body['query'] ?? '',
                'vars'    => $body['variables'] ?? [],
            ]);

            $message = $json['errors'][0]['message'] ?? ('HTTP ' . $response->status());
            throw new RuntimeException("Wave GraphQL request failed: {$message}");
        }

        return $json;
    }

    public function fetchBusinessId(WaveConnection $connection): string
    {
        $cached = Arr::get($connection->meta ?? [], 'business_id');

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $data = $this->graphqlPost($connection, [
            'query' => '{ businesses { edges { node { id name } } } }',
        ], $connection->access_token);

        $edges = Arr::get($data, 'data.businesses.edges', []);

        if (empty($edges)) {
            throw new RuntimeException('No Wave business found for this connection.');
        }

        $graphqlId = (string) Arr::get($edges[0], 'node.id', '');

        if ($graphqlId === '') {
            throw new RuntimeException('Wave API returned a business with no ID.');
        }

        // GraphQL returns base64 global ID e.g. "Business:7c6e10e4-..."
        // Webhook sends the plain UUID — decode and strip the type prefix
        $decoded    = base64_decode($graphqlId);
        $businessId = str_contains($decoded, ':')
            ? substr($decoded, strrpos($decoded, ':') + 1)
            : $graphqlId;

        $connection->forceFill(['meta' => array_merge($connection->meta ?? [], ['business_id' => $businessId])])->save();

        logger()->info('Wave: business_id stored', [
            'pms_client_id' => $connection->pms_client_id,
            'business_id'   => $businessId,
        ]);

        return $businessId;
    }

    public function fetchInvoice(WaveConnection $connection, string $invoiceId): array
    {
        // business(id:) takes the plain UUID stored during OAuth; invoice(id:) takes the raw ID from the webhook.
        $businessId = $this->fetchBusinessId($connection);

        // Wave's Business type has invoice(id:) singular — fields confirmed against Wave's schema.
        // amountDue only returns `value`, not nested currency; currency comes from the webhook payload.
        $query = <<<'GQL'
        query GetInvoice($businessId: ID!, $invoiceId: ID!) {
            business(id: $businessId) {
                invoice(id: $invoiceId) {
                    id
                    invoiceNumber
                    status
                    invoiceDate
                    dueDate
                    amountDue {
                        value
                    }
                    customer {
                        id
                        name
                        email
                    }
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
