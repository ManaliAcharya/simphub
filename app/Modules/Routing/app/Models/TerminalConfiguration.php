<?php

namespace Modules\Routing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TerminalConfiguration extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getCredentials(): array
    {
        if (empty($this->terminal_credentials)) {
            return [];
        }

        try {
            return (array) json_decode(decrypt($this->terminal_credentials), true);
        } catch (\Throwable) {
            return [];
        }
    }
}
