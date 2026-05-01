<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('zoho_default_account_id', 100)->nullable()->after('zoho_region');
            $table->string('zoho_default_account_name')->nullable()->after('zoho_default_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['zoho_default_account_id', 'zoho_default_account_name']);
        });
    }
};
