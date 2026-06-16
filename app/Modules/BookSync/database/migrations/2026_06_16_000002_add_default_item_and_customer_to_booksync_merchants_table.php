<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booksync_merchants', function (Blueprint $table) {
            $table->string('default_item_id', 64)->nullable()->after('deposit_account_name');
            $table->string('default_item_name', 255)->nullable()->after('default_item_id');
            $table->string('default_customer_id', 64)->nullable()->after('default_item_name');
            $table->string('default_customer_name', 255)->nullable()->after('default_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('booksync_merchants', function (Blueprint $table) {
            $table->dropColumn(['default_item_id', 'default_item_name', 'default_customer_id', 'default_customer_name']);
        });
    }
};
