<?php

namespace Modules\Inbound\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Inbound\Models\JobTreadConnection;

/**
 * JobTread's Pave API: one POST endpoint, the grant key passed as the query's "$".
 *
 * Every write SimpHub makes passes notify: false, so JobTread never emails the customer;
 * SimpHub's branded email is the only one they get. Query shapes live in JobTreadPave.
 */
class JobTreadApiClient
{
    /** Document field groups that failed with the current schema, logged once per process. */
    private static array $unavailableGroups = [];

    /**
     * Runs one Pave query and returns the response data (the query's shape, filled in).
     *
     * @throws JobTreadApiException
     */
    public function query(JobTreadConnection $connection, array $query): array
    {
        return $this->send((string) $connection->access_token, $query, (string) $connection->id);
    }

    /**
     * Same as query(), with a grant key that isn't saved yet (validating a pasted key).
     *
     * @throws JobTreadApiException
     */
    public function queryWithKey(string $grantKey, array $query): array
    {
        return $this->send($grantKey, $query, 'key:'.substr(hash('sha256', $grantKey), 0, 16));
    }

    /**
     * Organizations the grant key can act for: [['id' => ..., 'name' => ...], ...].
     *
     * @return list<array{id: string, name: string}>
     */
    public function organizationsForKey(string $grantKey): array
    {
        $data = $this->queryWithKey($grantKey, JobTreadPave::currentGrant());

        return collect((array) Arr::get($data, 'currentGrant.user.memberships.nodes', []))
            ->map(fn ($node) => (array) Arr::get((array) $node, 'organization', []))
            ->filter(fn (array $org) => ! empty($org['id']))
            ->map(fn (array $org) => ['id' => (string) $org['id'], 'name' => (string) ($org['name'] ?? '')])
            ->unique('id')
            ->values()
            ->all();
    }

    /** @return array{id: string, name: string}|null the organization, if this key can read it */
    public function organizationForKey(string $grantKey, string $organizationId): ?array
    {
        $data = $this->queryWithKey($grantKey, JobTreadPave::organization($organizationId));
        $org  = (array) Arr::get($data, 'organization', []);

        return empty($org['id']) ? null : ['id' => (string) $org['id'], 'name' => (string) ($org['name'] ?? '')];
    }

    /**
     * Reads a document with every field group available. If JobTread rejects the combined
     * query (a field name that doesn't match the schema), reads it group by group and leaves
     * out the groups that fail, so one wrong name can't stop invoices going out.
     *
     * @throws JobTreadApiException when even the core fields can't be read
     */
    public function fetchDocument(JobTreadConnection $connection, string $documentId): array
    {
        $groups = array_diff_key(JobTreadPave::DOCUMENT_GROUPS, self::$unavailableGroups);
        $fields = JobTreadPave::DOCUMENT_CORE;

        foreach ($groups as $group) {
            $fields = array_replace_recursive($fields, $group);
        }

        try {
            return $this->documentFrom($this->query($connection, JobTreadPave::document($documentId, $fields)), $documentId);
        } catch (JobTreadApiException $e) {
            if ($e->isAuthFailure() || $e->isTransient() || $e->isNotFound()) {
                throw $e;
            }
        }

        $document = $this->documentFrom(
            $this->query($connection, JobTreadPave::document($documentId, JobTreadPave::DOCUMENT_CORE)),
            $documentId,
        );

        foreach ($groups as $name => $group) {
            try {
                $part     = $this->query($connection, JobTreadPave::document($documentId, ['id' => []] + $group));
                $document = array_replace($document, (array) Arr::get($part, 'document', []));
            } catch (JobTreadApiException $e) {
                if ($e->isAuthFailure() || $e->isTransient()) {
                    throw $e;
                }

                self::$unavailableGroups[$name] = true;

                Log::warning('JobTread document field group unavailable; check JobTreadPave against the schema', [
                    'group' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $document;
    }

    /**
     * IDs of every customer invoice in a status, across pages.
     *
     * @return list<array<string, mixed>> core fields of each invoice
     */
    public function invoicesByStatus(JobTreadConnection $connection, string $organizationId, string $status, int $maxPages = 20): array
    {
        $invoices = [];
        $page     = null;

        for ($i = 0; $i < $maxPages; $i++) {
            $data  = $this->query($connection, JobTreadPave::invoicesByStatus($organizationId, $status, $page));
            $nodes = (array) Arr::get($data, 'organization.documents.nodes', []);

            foreach ($nodes as $node) {
                if (is_array($node) && ! empty($node['id'])) {
                    $invoices[] = $node;
                }
            }

            $page = Arr::get($data, 'organization.documents.nextPage');

            if (! is_string($page) || $page === '') {
                break;
            }
        }

        return $invoices;
    }

    /**
     * Payments carrying this externalId, with the documents each is applied to.
     *
     * @return list<array<string, mixed>>
     */
    public function paymentsByExternalId(JobTreadConnection $connection, string $organizationId, string $externalId): array
    {
        $data = $this->query($connection, JobTreadPave::paymentsByExternalId($organizationId, $externalId));

        return array_values(array_filter(
            (array) Arr::get($data, 'organization.payments.nodes', []),
            fn ($node) => is_array($node) && (string) ($node['externalId'] ?? '') === $externalId,
        ));
    }

    /** @return string the new payment's id */
    public function createPayment(JobTreadConnection $connection, array $input): string
    {
        $data = $this->query($connection, JobTreadPave::createPayment($input + ['notify' => false]));
        $id   = (string) Arr::get($data, 'createPayment.createdPayment.id', '');

        if ($id === '') {
            throw new JobTreadApiException('JobTread createPayment returned no payment id.', 200, $data);
        }

        return $id;
    }

    /** @return string the new document payment's id */
    public function createDocumentPayment(JobTreadConnection $connection, array $input): string
    {
        $data = $this->query($connection, JobTreadPave::createDocumentPayment($input + ['notify' => false]));

        return (string) Arr::get($data, 'createDocumentPayment.createdDocumentPayment.id', '');
    }

    /** @return string the webhook's id */
    public function createWebhook(JobTreadConnection $connection, string $organizationId, string $url, array $eventTypes): string
    {
        $data = $this->query($connection, JobTreadPave::createWebhook($organizationId, $url, $eventTypes));

        return (string) Arr::get($data, 'createWebhook.createdWebhook.id', '');
    }

    public function deleteWebhook(JobTreadConnection $connection, string $webhookId): void
    {
        $this->query($connection, JobTreadPave::deleteWebhook($webhookId));
    }

    /**
     * JobTread's own PDF of a document, or null when it can't be had (SimpHub then attaches
     * its own). Off unless services.jobtread.native_pdf is on, until first-day test #7
     * confirms the signQuery + /t/{token} pattern.
     */
    public function fetchDocumentPdf(JobTreadConnection $connection, string $documentId): ?string
    {
        if (! config('services.jobtread.native_pdf')) {
            return null;
        }

        try {
            $data  = $this->query($connection, JobTreadPave::signQuery(JobTreadPave::documentPdf($documentId)));
            $token = Arr::get($data, 'signQuery');
            $token = is_array($token) ? ($token['token'] ?? null) : $token;

            if (! is_string($token) || $token === '') {
                return null;
            }

            $response = Http::timeout(30)->get(config('services.jobtread.pdf_base_url').'/'.rawurlencode($token));

            if (! $response->successful() || ! str_starts_with($response->body(), '%PDF')) {
                return null;
            }

            return $response->body();
        } catch (\Throwable $e) {
            Log::warning('JobTread PDF unavailable; attaching the SimpHub PDF instead', [
                'document_id' => $documentId,
                'error'       => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** @throws JobTreadApiException */
    private function send(string $grantKey, array $query, string $throttleKey): array
    {
        if (trim($grantKey) === '') {
            throw new JobTreadApiException('JobTread grant key is missing.', 401);
        }

        $body = ['query' => ['$' => ['grantKey' => $grantKey]] + $query];

        for ($attempt = 1; ; $attempt++) {
            $this->throttle($throttleKey);

            try {
                $response = Http::acceptJson()->asJson()->timeout(30)->post(config('services.jobtread.api_url'), $body);
            } catch (ConnectionException $e) {
                if ($attempt < 3) {
                    usleep(500_000 * $attempt);

                    continue;
                }

                throw new JobTreadApiException('JobTread unreachable: '.$e->getMessage());
            }

            if (($response->status() === 429 || $response->serverError()) && $attempt < 3) {
                $retryAfter = (int) $response->header('Retry-After');
                usleep(max($retryAfter, $attempt) * 1_000_000);

                continue;
            }

            $json = $response->json();

            if ($response->failed()) {
                throw new JobTreadApiException(
                    'JobTread API error '.$response->status().': '.$this->errorMessage($json, $response->body()),
                    $response->status(),
                    $json ?? $response->body(),
                );
            }

            if (! is_array($json)) {
                throw new JobTreadApiException('JobTread returned a non-JSON response.', $response->status(), $response->body());
            }

            if (isset($json['errors']) || isset($json['error'])) {
                throw new JobTreadApiException('JobTread API error: '.$this->errorMessage($json, ''), $response->status(), $json);
            }

            return $json;
        }
    }

    private function errorMessage(mixed $json, string $raw): string
    {
        if (is_array($json)) {
            $message = $json['message']
                ?? Arr::get($json, 'error.message')
                ?? (is_string($json['error'] ?? null) ? $json['error'] : null)
                ?? Arr::get($json, 'errors.0.message')
                ?? null;

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return mb_substr(trim($raw), 0, 300) ?: 'unknown error';
    }

    /** JobTread publishes no rate limits: keep requests per connection spaced out. */
    private function throttle(string $key): void
    {
        $intervalMs = (int) config('services.jobtread.min_request_interval_ms', 350);

        if ($intervalMs <= 0) {
            return;
        }

        $cacheKey = 'jobtread:last-request:'.$key;
        $last     = (float) Cache::get($cacheKey, 0);
        $waitMs   = $intervalMs - (microtime(true) - $last) * 1000;

        if ($waitMs > 0) {
            usleep((int) ($waitMs * 1000));
        }

        Cache::put($cacheKey, microtime(true), 60);
    }

    private function documentFrom(array $data, string $documentId): array
    {
        $document = Arr::get($data, 'document');

        if (! is_array($document) || empty($document['id'])) {
            throw new JobTreadApiException("JobTread document [{$documentId}] was not found.", 404, $data);
        }

        return $document;
    }
}
