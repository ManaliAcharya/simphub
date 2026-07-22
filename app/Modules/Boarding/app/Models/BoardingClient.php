<?php

namespace Modules\Boarding\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Modules\Auth\Models\ClientAccount;

class BoardingClient extends Model
{
    use HasUuids;

    protected $table = 'boarding_clients';

    protected $guarded = [];

    protected $hidden = ['client_api_key', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'client_api_key'     => 'encrypted',
            'webhook_secret'     => 'encrypted',
            'allowed_processors' => 'array',
        ];
    }

    public function account(): MorphOne
    {
        return $this->morphOne(ClientAccount::class, 'owner');
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(BoardingMerchant::class, 'client_id');
    }

    public function masterLinks(): HasMany
    {
        return $this->hasMany(BoardingMasterLink::class, 'client_id');
    }

    public function masterLinkFor(string $processor, string $tier): ?BoardingMasterLink
    {
        return $this->masterLinks()
            ->where('processor', $processor)
            ->where('tier', $tier)
            ->first();
    }
}
