<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_mid_routes', function (Blueprint $table): void {
            // Multi-MID rates need the same 5-decimal precision as the client fee percents
            // (e.g. 3.48753) for law firms that combine IOLTA trust deposits with Multi-MID.
            $table->decimal('rate_percent', 8, 5)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('client_mid_routes', function (Blueprint $table): void {
            $table->decimal('rate_percent', 5, 2)->nullable()->change();
        });
    }
};
