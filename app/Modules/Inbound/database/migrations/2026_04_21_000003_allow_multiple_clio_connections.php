<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clio_connections', function (Blueprint $table): void {
            $table->dropUnique(['provider']);
            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::table('clio_connections', function (Blueprint $table): void {
            $table->dropIndex(['provider']);
            $table->unique('provider');
        });
    }
};
