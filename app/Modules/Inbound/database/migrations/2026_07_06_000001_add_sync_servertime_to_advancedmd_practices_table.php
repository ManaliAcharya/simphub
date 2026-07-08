<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advancedmd_practices', function (Blueprint $table): void {
            $table->string('last_sync_servertime')->nullable()->after('last_polled_at');
        });
    }

    public function down(): void
    {
        Schema::table('advancedmd_practices', function (Blueprint $table): void {
            $table->dropColumn('last_sync_servertime');
        });
    }
};
