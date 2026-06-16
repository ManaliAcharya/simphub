<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booksync_merchants', function (Blueprint $table) {
            $table->string('default_income_account_id', 64)->nullable()->after('deposit_account_name');
            $table->string('default_income_account_name', 255)->nullable()->after('default_income_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('booksync_merchants', function (Blueprint $table) {
            $table->dropColumn(['default_income_account_id', 'default_income_account_name']);
        });
    }
};
