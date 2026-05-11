<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('lawcus_default_bank_account_id', 100)->nullable()->after('qb_default_account_name');
            $table->string('lawcus_default_bank_account_name')->nullable()->after('lawcus_default_bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['lawcus_default_bank_account_id', 'lawcus_default_bank_account_name']);
        });
    }
};
