<?php

namespace Modules\Routing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RoutingRule extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['mid_credentials'];

    protected function casts(): array
    {
        return [
            'mid_credentials' => 'encrypted:array',
            'is_active'       => 'boolean',
        ];
    }
}
