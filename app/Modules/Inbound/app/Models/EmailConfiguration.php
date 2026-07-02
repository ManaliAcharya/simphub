<?php

namespace Modules\Inbound\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailConfiguration extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attach_pdf' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
