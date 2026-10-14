<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Inbound\Models\PmsConnection;
use RuntimeException;

class ZohoCrmApiClient
{
    private const CONTACT_FIELDS = 'id,Email,Full_Name';

    public function __construct(
        private readonly ZohoRegionResolver $regions,
    ) {}

    public function fetchOrganization(PmsConnection $connection): array
    {
        return (array) ($this->request($connection)->get('/org')->throw()->json('org.0') ?? []);
    }

    /** null when the id is not a Contact in this org (Zoho answers 204 or INVALID_DATA). */
    public function fetchContact(PmsConnection $connection, string $contactId): ?array
    {
        $response = $this->request($connection)->get('/Contacts/'.rawurlencode($contactId), [
            'fields' => self::CONTACT_FIELDS,
        ]);

        if ($response->status() === 204 || in_array($response->status(), [400, 404], true)) {
            return null;
        }

        return $response->throw()->json('data.0');
    }

    /** @return array<int, array> */
    public function searchContactsByEmail(PmsConnection $connection, string $email): array
    {
        $response = $this->request($connection)->get('/Contacts/search', [
            'email' => $email,
            'fields' => self::CONTACT_FIELDS,
        ]);

        if ($response->status() === 204) {
            return [];
        }

        return (array) ($response->throw()->json('data') ?? []);
    }

    public function updateContact(PmsConnection $connection, string $contactId, array $fields): void
    {
        $response = $this->request($connection)->put('/Contacts/'.rawurlencode($contactId), [
            'data' => [$fields],
        ]);

        $this->assertSuccess($response, 'data', 'Zoho CRM contact update failed');
    }

    /** @return array<int, array> Contacts module field metadata. */
    public function fetchContactFields(PmsConnection $connection): array
    {
        return (array) ($this->request($connection)
            ->get('/settings/fields', ['module' => 'Contacts'])
            ->throw()
            ->json('fields') ?? []);
    }

    public function createContactField(PmsConnection $connection, array $field): void
    {
        $response = $this->request($connection)
            ->withQueryParameters(['module' => 'Contacts'])
            ->post('/settings/fields', ['fields' => [$field]]);

        $this->assertSuccess($response, 'fields', "Could not create Zoho CRM field \"{$field['field_label']}\"");
    }

    private function request(PmsConnection $connection): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout(20)
            ->baseUrl($this->regions->apiDomainForConnection($connection).'/crm/'.config('services.zohocrm.api_version', 'v8'))
            ->withHeaders(['Authorization' => 'Zoho-oauthtoken '.$connection->access_token]);
    }

    /** Zoho reports per-record results inside the body, sometimes with HTTP 200/202. */
    private function assertSuccess(Response $response, string $key, string $message): void
    {
        $result = $response->json("{$key}.0") ?? $response->json();

        if ($response->successful() && strtolower((string) data_get($result, 'status')) === 'success') {
            return;
        }

        $detail = trim(implode(' ', array_filter([
            data_get($result, 'code'),
            data_get($result, 'message'),
            ($apiName = data_get($result, 'details.api_name')) ? "(field {$apiName})" : null,
        ]))) ?: 'HTTP '.$response->status();

        throw new RuntimeException("{$message}: {$detail}");
    }
}
