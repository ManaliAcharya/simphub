<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->boolean('cash_discount_enabled')->default(false)->after('fee_surcharge_enabled');
            $table->decimal('cash_discount_percent', 5, 2)->nullable()->after('cash_discount_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['cash_discount_enabled', 'cash_discount_percent']);
        });
    }
};
