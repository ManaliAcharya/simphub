<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('fee_mode', 20)->default('surcharge')->after('fee_surcharge_enabled');
            $table->text('fee_disclosure')->nullable()->after('fee_mode');
            $table->json('cash_discount_details')->nullable()->after('fee_disclosure');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['fee_mode', 'fee_disclosure', 'cash_discount_details']);
        });
    }
};
