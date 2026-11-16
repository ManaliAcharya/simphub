<?php

namespace Modules\Inbound\Services;

use Illuminate\Support\Arr;

/**
 * Pure rules for Clio bills (invoices and Trust Requests), shared by ingestion, the payment
 * page's live balance check, and the invoice list.
 */
final class ClioBillRules
{
    /** Stored at raw_payload.email_skipped when SimpHub deliberately sends no payment email. */
    public const SKIP_LEDES = 'ledes';

    /** The matter-level checkbox custom field a firm ticks for an insurer-billed matter. */
    public const LEDES_FIELD_NAME = 'LEDES';

    /**
     * Why the bill's matter(s) get no payment email: Clio's own LEDES billing setting
     * (require_utbms_codes), or the firm's "LEDES" checkbox custom field. Either one on any
     * of the bill's matters skips it. Null when neither is set.
     *
     * @param  array<int, array>  $matters  matter records (the `data` of GET /matters/{id})
     */
    public static function ledesSkipReason(array $matters): ?string
    {
        foreach ($matters as $matter) {
            if (! is_array($matter)) {
                continue;
            }

            if (self::truthy($matter['require_utbms_codes'] ?? null)) {
                return 'native_ledes_setting';
            }

            foreach ((array) ($matter['custom_field_values'] ?? []) as $field) {
                if (is_array($field)
                    && strcasecmp(trim((string) ($field['field_name'] ?? '')), self::LEDES_FIELD_NAME) === 0
                    && self::truthy($field['value'] ?? null)) {
                    return 'ledes_custom_field';
                }
            }
        }

        return null;
    }

    /** What's still owed: Clio's balance (total less payments and applied trust funds). */
    public static function amountDue(array $bill): float
    {
        $balance = self::money($bill['balance'] ?? null);

        return $balance ?? (self::money($bill['total'] ?? null) ?? 0.0);
    }

    /**
     * Why the bill can no longer be paid: 'voided' (void or deleted in Clio) or 'paid' (paid,
     * or nothing left owing — e.g. covered by trust funds). Null while it's still payable.
     */
    public static function closedReason(array $bill): ?string
    {
        $state = strtolower(trim((string) ($bill['state'] ?? '')));

        if (in_array($state, ['void', 'deleted'], true)) {
            return 'voided';
        }

        if ($state === 'paid') {
            return 'paid';
        }

        $balance = self::money($bill['balance'] ?? null);

        return $balance !== null && $balance <= 0.0 ? 'paid' : null;
    }

    public static function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /** Ids of every matter the bill is for (GET /bills `matters`). */
    public static function matterIds(array $bill): array
    {
        $ids = collect((array) ($bill['matters'] ?? []))
            ->map(fn ($matter) => is_array($matter) ? (string) ($matter['id'] ?? '') : '')
            ->push((string) Arr::get($bill, 'matter.id', ''))
            ->filter(fn (string $id) => $id !== '')
            ->unique()
            ->values()
            ->all();

        return $ids;
    }

    private static function money(mixed $value): ?float
    {
        if (is_array($value)) {
            $value = $value['amount'] ?? $value['value'] ?? null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value > 0;
        }

        return in_array(strtolower(trim((string) $value)), ['true', '1', 'yes', 'y', 'checked', 'on'], true);
    }
}
