<?php

namespace Modules\Routing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RoutingRule extends Model
{
    use HasUuids;

    protected $guarded = [];
}
