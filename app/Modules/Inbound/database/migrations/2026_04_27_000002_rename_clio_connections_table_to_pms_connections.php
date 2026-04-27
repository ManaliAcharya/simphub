<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('clio_connections') && ! Schema::hasTable('pms_connections')) {
            Schema::rename('clio_connections', 'pms_connections');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pms_connections') && ! Schema::hasTable('clio_connections')) {
            Schema::rename('pms_connections', 'clio_connections');
        }
    }
};
