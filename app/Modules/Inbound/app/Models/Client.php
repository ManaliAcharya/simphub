<?php

namespace Modules\Inbound\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'webhook_flow_enabled'     => 'boolean',
            'call_api_to_pms'          => 'boolean',
            'client_calls_our_api'     => 'boolean',
            'allowed_payment_gateways' => 'array',
            'paused_payment_gateways'  => 'array',
            'allowed_terminals'        => 'array',
            'fee_surcharge_enabled'       => 'boolean',
            'cash_discount_details'       => 'array',
            'qb_fee_override_enabled'     => 'boolean',
            'qb_multi_mid_enabled'        => 'boolean',
        ];
    }

    public function usesTerminal(): bool
    {
        return ! empty($this->allowed_terminals);
    }
}
