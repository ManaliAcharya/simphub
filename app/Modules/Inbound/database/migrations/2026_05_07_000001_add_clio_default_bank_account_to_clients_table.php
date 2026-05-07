<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('clio_default_bank_account_id', 100)->nullable()->after('zoho_default_account_name');
            $table->string('clio_default_bank_account_name')->nullable()->after('clio_default_bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['clio_default_bank_account_id', 'clio_default_bank_account_name']);
        });
    }
};
