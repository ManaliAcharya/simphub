<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            // decimal(5,2) only allows 3 integer digits + 2 decimal digits — not enough to hold
            // a 5-decimal surcharge rate (e.g. 3.48753) needed for exact-to-the-cent IOLTA trust
            // deposits. decimal(8,5) keeps room for a 3-digit whole percent plus 5 decimals.
            $table->decimal('cc_fee_percent', 8, 5)->nullable()->change();
            $table->decimal('ach_fee_percent', 8, 5)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->decimal('cc_fee_percent', 5, 2)->nullable()->change();
            $table->decimal('ach_fee_percent', 5, 2)->nullable()->change();
        });
    }
};
