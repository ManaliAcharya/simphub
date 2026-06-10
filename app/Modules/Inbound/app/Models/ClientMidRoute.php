<?php

namespace Modules\Inbound\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientMidRoute extends Model
{
    use HasUuids;

    protected $table = 'client_mid_routes';
    protected $guarded = [];
    protected $hidden  = ['credentials'];

    protected function casts(): array
    {
        return [
            'rate_percent' => 'decimal:2',
            'is_active'    => 'boolean',
            'credentials'  => 'encrypted:array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
