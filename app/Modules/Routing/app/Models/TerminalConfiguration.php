<?php

namespace Modules\Routing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TerminalConfiguration extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['terminal_credentials'];

    protected function casts(): array
    {
        return [
            'is_active'            => 'boolean',
            'terminal_credentials' => 'encrypted:array',
        ];
    }

    public function getCredentials(): array
    {
        try {
            return (array) ($this->terminal_credentials ?? []);
        } catch (\Throwable) {
            return [];
        }
    }
}
