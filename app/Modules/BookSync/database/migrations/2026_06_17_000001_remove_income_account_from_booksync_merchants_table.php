<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booksync_merchants', function (Blueprint $table) {
            if (Schema::hasColumn('booksync_merchants', 'default_income_account_id')) {
                $table->dropColumn(['default_income_account_id', 'default_income_account_name']);
            }
        });
    }

    public function down(): void {}
};
