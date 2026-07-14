<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('cardholder_first_name', 100)->nullable()->after('gateway_response');
            $table->string('cardholder_last_name', 100)->nullable()->after('cardholder_first_name');
            $table->json('billing_address')->nullable()->after('cardholder_last_name');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn(['cardholder_first_name', 'cardholder_last_name', 'billing_address']);
        });
    }
};
