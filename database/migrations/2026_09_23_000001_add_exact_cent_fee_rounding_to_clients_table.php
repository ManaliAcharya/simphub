<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            // Per-client opt-in for the exact-cent/ceiling fee calculation (IOLTA trust-account
            // compliance). Off by default so every existing client keeps the old round-nearest
            // behavior unless explicitly switched over.
            $table->boolean('exact_cent_fee_rounding_enabled')->default(false)->after('ach_fee_percent');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('exact_cent_fee_rounding_enabled');
        });
    }
};
