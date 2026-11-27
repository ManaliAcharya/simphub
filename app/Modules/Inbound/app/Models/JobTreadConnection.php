<?php

namespace Modules\Inbound\Models;

/**
 * A client's JobTread connection, one row in pms_connections (provider "jobtread").
 *
 * - access_token:   the grant key HEP creates in JobTread (Settings > Integrations > API > Grants), encrypted.
 * - webhook_secret: the random token in this client's webhook URL. JobTread webhooks carry
 *                   no signature, so this token is the only proof a call came from our registration.
 * - meta:           organization_id / organization_name, webhook state, poll state.
 */
class JobTreadConnection extends PmsConnection
{
    public const PROVIDER = 'jobtread';

    public function organizationId(): ?string
    {
        $id = trim((string) (((array) ($this->meta ?? []))['organization_id'] ?? ''));

        return $id !== '' ? $id : null;
    }

    public static function forClient(?string $pmsClientId): ?self
    {
        if (! $pmsClientId) {
            return null;
        }

        return self::query()
            ->where('provider', self::PROVIDER)
            ->where('pms_client_id', $pmsClientId)
            ->latest('created_at')
            ->first();
    }
}
