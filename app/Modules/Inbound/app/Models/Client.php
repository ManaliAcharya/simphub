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
