<?php

namespace Modules\Boarding\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoardingMasterLink extends Model
{
    use HasUuids;

    protected $table = 'boarding_master_links';

    protected $guarded = [];

    public function client(): BelongsTo
    {
        return $this->belongsTo(BoardingClient::class, 'client_id');
    }
}
