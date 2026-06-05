<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->boolean('qb_fee_override_enabled')->default(false)->after('fee_surcharge_enabled');
            $table->string('qb_fee_override_field', 100)->nullable()->default('Cash Discount')->after('qb_fee_override_enabled');
            $table->boolean('qb_multi_mid_enabled')->default(false)->after('qb_fee_override_field');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['qb_fee_override_enabled', 'qb_fee_override_field', 'qb_multi_mid_enabled']);
        });
    }
};
