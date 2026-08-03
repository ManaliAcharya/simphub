<?php

namespace Modules\Inbound\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Modules\Auth\Models\ClientAccount;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasUuids;

    use SoftDeletes;

    protected $dates = ['deleted_at'];

    protected $guarded = [];

    protected $hidden = ['gateway_credentials'];

    protected function casts(): array
    {
        return [
            'webhook_flow_enabled'     => 'boolean',
            'call_api_to_pms'          => 'boolean',
            'client_calls_our_api'     => 'boolean',
            'allowed_payment_gateways' => 'array',
            'paused_payment_gateways'  => 'array',
            'gateway_credentials'      => 'encrypted:array',
            'gateway_display_names'    => 'array',
            'allowed_terminals'        => 'array',
            'fee_surcharge_enabled'       => 'boolean',
            'cash_discount_details'       => 'array',
            'qb_fee_override_enabled'        => 'boolean',
            'qb_multi_mid_enabled'           => 'boolean',
            'qb_surcharge_enabled'           => 'boolean',
            'wave_surcharge_enabled'         => 'boolean',
            'payment_link_override_enabled'  => 'boolean',
            'reminders_enabled'              => 'boolean',
            'reminder_schedule_days'         => 'array',
        ];
    }

    public function emailConfiguration(): HasOne
    {
        return $this->hasOne(EmailConfiguration::class, 'client_id');
    }

    public function usesTerminal(): bool
    {
        return ! empty($this->allowed_terminals);
    }

    /**
     * Effective reminder cadence as an array of ints, falling back to the system
     * default. Tolerates reminder_schedule_days having been stored as a raw
     * comma string rather than a JSON array (e.g. from data written before/outside
     * the normal save path) — the array cast doesn't error on that, it just leaves
     * the value as a string, which used to blow up every count()/implode() caller.
     */
    public function reminderScheduleDays(): array
    {
        $days = $this->reminder_schedule_days;

        if (is_string($days)) {
            $days = array_map(static fn ($d) => (int) trim($d), explode(',', $days));
        }

        if (! is_array($days) || $days === []) {
            return config('reminders.default_schedule_days');
        }

        $days = array_values(array_filter($days, static fn ($d) => is_numeric($d) && (int) $d > 0));

        return $days !== [] ? $days : config('reminders.default_schedule_days');
    }

    public function gatewayDisplayName(string $gateway): string
    {
        $names = (array) ($this->gateway_display_names ?? []);
        $name  = trim((string) ($names[strtolower($gateway)] ?? ''));

        return $name !== '' ? $name : strtoupper($gateway);
    }

    public function account(): MorphOne
    {
        return $this->morphOne(ClientAccount::class, 'owner');
    }
}
