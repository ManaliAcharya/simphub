<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\WaveConnection;
use RuntimeException;

class WaveApiClient
{
    public function graphqlRequest(WaveConnection $connection): PendingRequest
    {
        return Http::withToken($connection->access_token)
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
