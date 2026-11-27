<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Audit\Services\AuditLogger;
use Modules\Inbound\Mail\JobTreadConnectionBrokenMail;
use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\JobTreadConnection;
use RuntimeException;
use Throwable;

/**
 * Connects a client to JobTread with a grant key pasted from JobTread
 * (Settings > Integrations > API > Grants; shown once at creation).
 *
 * On connect: the key is checked, the organization picked, invoices already pending are
 * recorded as a baseline (so connecting never emails the existing backlog), and a webhook
 * registered. The webhook is only a "something changed" signal; PollJobTreadInvoicesJob
 * does the actual reading, so a missing or failing webhook only delays invoices.
 */
class JobTreadConnectionService
{
    /** Pending invoices recorded as the baseline at connect; more than this are left out. */
    private const BASELINE_LIMIT = 300;

    public function __construct(
        private readonly JobTreadApiClient $api,
    ) {}

    public function connect(string $grantKey, string $pmsClientId, ?string $organizationId = null): JobTreadConnection
    {
        $grantKey       = trim($grantKey);
        $organizationId = trim((string) $organizationId) ?: null;

        if ($grantKey === '') {
            throw new RuntimeException('Grant key is required.');
        }

        if (! Client::query()->where('pms_client_id', $pmsClientId)->exists()) {
            throw new RuntimeException("Unknown client for PMS client identifier [{$pmsClientId}].");
        }

        $organization = $this->resolveOrganization($grantKey, $organizationId);
        $previous     = JobTreadConnection::forClient($pmsClientId);

        $connection = $previous ?? new JobTreadConnection([
            'provider'      => JobTreadConnection::PROVIDER,
            'pms_client_id' => $pmsClientId,
        ]);

        $sameAccount = $previous
            && $previous->organizationId() === $organization['id'];

        $meta = $sameAccount ? (array) ($previous->meta ?? []) : [];

        $connection->forceFill([
            'provider'             => JobTreadConnection::PROVIDER,
            'pms_client_id'        => $pmsClientId,
            'access_token'         => $grantKey,
            'refresh_token'        => null,
            'token_expires_at'     => null,
            'consecutive_failures' => 0,
            'last_error'           => null,
            // A fresh URL token on every connect; the old webhook URL stops being accepted.
            'webhook_secret'       => Str::random(48),
            'meta'                 => array_merge($meta, [
                'organization_id'   => $organization['id'],
                'organization_name' => $organization['name'],
                'connected_at'      => now()->toIso8601String(),
            ]),
        ])->save();

        if (! $sameAccount) {
            $this->recordBaseline($connection);
        }

        // Registering a webhook writes to the client's JobTread account; while it's switched
        // off the 5-minute poll is the only way invoices are found.
        if (config('services.jobtread.webhooks_enabled')) {
            $this->registerWebhook($connection);
        }

        AuditLogger::log('JOBTREAD_CONNECTED', 'client', $pmsClientId, [
            'organization_id'   => $organization['id'],
            'organization_name' => $organization['name'],
            'reconnect'         => (bool) $previous,
        ]);

        return $connection->fresh();
    }

    public function webhookUrl(JobTreadConnection $connection): string
    {
        $url = (string) config('services.jobtread.webhook_callback_url');

        if (trim($url) === '') {
            throw new RuntimeException('Missing JobTread webhook callback URL.');
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query([
            'pms_client_id' => $connection->pms_client_id,
            'token'         => $connection->webhook_secret,
        ]);
    }

    /** Registers (or re-registers) the webhook. Non-fatal: polling covers a missing webhook. */
    public function registerWebhook(JobTreadConnection $connection): void
    {
        $meta = (array) ($connection->meta ?? []);

        if (! empty($meta['webhook_id'])) {
            try {
                $this->api->deleteWebhook($connection, (string) $meta['webhook_id']);
            } catch (Throwable $e) {
                Log::info('JobTread: old webhook not deleted', ['pms_client_id' => $connection->pms_client_id, 'error' => $e->getMessage()]);
            }
        }

        try {
            $url       = $this->webhookUrl($connection);
            $webhookId = $this->api->createWebhook(
                $connection,
                (string) $connection->organizationId(),
                $url,
                (array) config('services.jobtread.webhook_events', []),
            );

            $connection->forceFill([
                'webhook_id'  => $webhookId ?: null,
                'webhook_url' => $url,
                'meta'        => array_merge($meta, ['webhook_id' => $webhookId ?: null, 'webhook_error' => null]),
            ])->save();
        } catch (Throwable $e) {
            $connection->forceFill([
                'webhook_id' => null,
                'meta'       => array_merge($meta, ['webhook_id' => null, 'webhook_error' => $e->getMessage()]),
            ])->save();

            Log::warning('JobTread webhook registration failed; relying on polling', [
                'pms_client_id' => $connection->pms_client_id,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    public function disconnect(JobTreadConnection $connection): void
    {
        $webhookId = ((array) ($connection->meta ?? []))['webhook_id'] ?? null;

        if ($webhookId) {
            try {
                $this->api->deleteWebhook($connection, (string) $webhookId);
            } catch (Throwable $e) {
                Log::info('JobTread: webhook not deleted on disconnect', ['pms_client_id' => $connection->pms_client_id, 'error' => $e->getMessage()]);
            }
        }

        $connection->delete();
    }

    public function recordApiSuccess(JobTreadConnection $connection): void
    {
        $meta = (array) ($connection->meta ?? []);

        // Doubles as the record of the key's last use: grant keys reportedly expire after
        // 3 months unused, and the poll uses it every few minutes.
        $meta['last_api_success_at'] = now()->toIso8601String();

        $connection->forceFill([
            'consecutive_failures' => 0,
            'last_error'           => null,
            'meta'                 => $meta,
        ])->save();
    }

    /**
     * Counts auth failures only (revoked or expired grant key). On the healthy-to-broken
     * transition, ops is emailed once.
     */
    public function recordApiFailure(JobTreadConnection $connection, Throwable $exception): void
    {
        if (! $exception instanceof JobTreadApiException || ! $exception->isAuthFailure()) {
            return;
        }

        $justBroke = (int) $connection->consecutive_failures === 0;

        $connection->forceFill([
            'consecutive_failures' => (int) $connection->consecutive_failures + 1,
            'last_error'           => $exception->getMessage(),
        ])->save();

        if (! $justBroke) {
            return;
        }

        $opsEmail = trim((string) config('services.jobtread.ops_alert_email'));
        $client   = Client::query()->where('pms_client_id', $connection->pms_client_id)->first();

        if ($opsEmail === '' || ! $client) {
            Log::warning('JobTread connection broken; no ops alert email configured', [
                'pms_client_id' => $connection->pms_client_id,
                'error'         => $exception->getMessage(),
            ]);

            return;
        }

        try {
            Mail::to($opsEmail)->send(new JobTreadConnectionBrokenMail($client, $exception->getMessage()));
        } catch (Throwable $e) {
            Log::error('JobTread connection-broken alert failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The organization to bill for: the one given (checked against the key), or the key's
     * only organization.
     *
     * @return array{id: string, name: string}
     */
    private function resolveOrganization(string $grantKey, ?string $organizationId): array
    {
        $organizations = null;

        try {
            $organizations = $this->api->organizationsForKey($grantKey);
        } catch (JobTreadApiException $e) {
            if ($e->isAuthFailure()) {
                throw new RuntimeException('JobTread rejected this grant key: '.$e->getMessage());
            }

            // Discovery query may not match the schema yet; a given organization ID still works.
            if ($organizationId === null) {
                throw new RuntimeException('Could not list the organizations for this grant key ('.$e->getMessage().'). Enter the JobTread organization ID.');
            }
        }

        if ($organizationId !== null) {
            foreach ((array) $organizations as $org) {
                if ($org['id'] === $organizationId) {
                    return $org;
                }
            }

            try {
                $org = $this->api->organizationForKey($grantKey, $organizationId);
            } catch (JobTreadApiException $e) {
                throw new RuntimeException('This grant key cannot read organization '.$organizationId.': '.$e->getMessage());
            }

            if (! $org) {
                throw new RuntimeException('This grant key cannot read organization '.$organizationId.'.');
            }

            return $org;
        }

        if (count($organizations) === 1) {
            return $organizations[0];
        }

        if ($organizations === []) {
            throw new RuntimeException('This grant key has no JobTread organization.');
        }

        throw new RuntimeException('This grant key has access to several organizations ('
            .implode(', ', array_map(fn ($o) => "{$o['name']} [{$o['id']}]", $organizations))
            .'). Enter the organization ID to use.');
    }

    /**
     * Invoices already pending when the client connects, with what they looked like then.
     * They're never emailed as they stand; one edited and pushed again later is (see
     * JobTreadInvoiceIngestionService). Until a baseline is recorded nothing is ingested:
     * a failure here leaves it unset and the next poll tries again (PollJobTreadInvoicesJob).
     */
    public function recordBaseline(JobTreadConnection $connection): bool
    {
        $baseline = [];

        try {
            $pending = $this->api->invoicesByStatus($connection, (string) $connection->organizationId(), JobTreadPave::STATUS_PENDING);

            foreach (array_slice($pending, 0, self::BASELINE_LIMIT) as $row) {
                $document               = $this->api->fetchDocument($connection, (string) $row['id']);
                $baseline[$row['id']] = JobTreadDocument::fingerprint($document);
            }
        } catch (Throwable $e) {
            Log::warning('JobTread: baseline of pending invoices not recorded; retried on the next poll', [
                'pms_client_id' => $connection->pms_client_id,
                'error'         => $e->getMessage(),
            ]);

            return false;
        }

        $connection->forceFill([
            'meta' => array_merge((array) $connection->meta, [
                'baseline'             => $baseline,
                'baseline_recorded_at' => now()->toIso8601String(),
            ]),
        ])->save();

        return true;
    }

    public static function hasBaseline(JobTreadConnection $connection): bool
    {
        return array_key_exists('baseline', (array) ($connection->meta ?? []));
    }
}
