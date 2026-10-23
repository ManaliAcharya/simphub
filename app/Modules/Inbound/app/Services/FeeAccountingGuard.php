<?php

namespace Modules\Inbound\Services;

use Modules\Inbound\Models\Client;
use Modules\Inbound\Models\ClientMidRoute;

/**
 * A fee charged on top of the invoice only reaches the right place in the PMS when
 * the payment sync can split it out: QuickBooks and Wave record the invoice amount
 * as the payment and post the fee to a surcharge income account, which needs the
 * surcharge split on, a surcharge account and a deposit account. Without them the
 * fee lands as an unapplied customer credit (or isn't recorded at all), so the
 * settings pages warn whenever a client charges fees without all three.
 *
 * Zoho, Clio, AdvancedMD and Lawcus have no fee account setting, so there's
 * nothing to require for them.
 */
class FeeAccountingGuard
{
    /** PMS => settings prefix on the clients table. */
    private const SPLIT_PMS = [
        'QUICKBOOKS' => 'qb',
        'WAVE'       => 'wave',
    ];

    /**
     * Settings still missing for this client's fees to be booked correctly, as
     * user-facing phrases. Empty when nothing is missing or no fee is charged.
     *
     * @param  array<string, mixed>  $overrides  unsaved values to check in place of the client's own
     *                                           (keys are clients columns, plus 'has_mid_route_fee')
     * @return array<int, string>
     */
    public function missingSettings(Client $client, array $overrides = []): array
    {
        $prefix = self::SPLIT_PMS[strtoupper((string) $client->client_pms)] ?? null;

        if ($prefix === null || ! $this->chargesFees($client, $overrides)) {
            return [];
        }

        $value   = fn (string $key) => array_key_exists($key, $overrides) ? $overrides[$key] : $client->{$key};
        $missing = [];

        if (! $value("{$prefix}_surcharge_enabled")) {
            $missing[] = 'the surcharge split turned on';
        }

        if (trim((string) $value("{$prefix}_surcharge_account_id")) === '') {
            $missing[] = 'a surcharge income account';
        }

        if (trim((string) $value("{$prefix}_default_account_id")) === '') {
            $missing[] = 'a deposit account';
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $overrides  see missingSettings()
     */
    public function chargesFees(Client $client, array $overrides = []): bool
    {
        $value = fn (string $key) => array_key_exists($key, $overrides) ? $overrides[$key] : $client->{$key};

        if ($value('fee_surcharge_enabled')
            && ((float) $value('cc_fee_percent') > 0 || (float) $value('ach_fee_percent') > 0)) {
            return true;
        }

        // QuickBooks Multi-MID: a fees_on route with a rate adds a fee of its own.
        if (strtoupper((string) $client->client_pms) !== 'QUICKBOOKS' || ! $value('qb_multi_mid_enabled')) {
            return false;
        }

        if (array_key_exists('has_mid_route_fee', $overrides)) {
            return (bool) $overrides['has_mid_route_fee'];
        }

        return $client->exists && ClientMidRoute::query()
            ->where('client_id', $client->id)
            ->where('route_type', 'fees_on')
            ->where('rate_percent', '>', 0)
            ->exists();
    }

    /**
     * @param  array<int, string>  $missing
     */
    public function message(Client $client, array $missing): string
    {
        $pms = strtoupper((string) $client->client_pms) === 'WAVE' ? 'Wave' : 'QuickBooks';

        return "Surcharge fees won't be synced to {$pms} correctly: the {$pms} integration needs "
            .$this->join($missing)
            .". Until then each fee is recorded with the invoice payment (a customer credit) instead of as fee income.";
    }

    /**
     * @param  array<int, string>  $items
     */
    private function join(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' and '.$last;
    }
}
