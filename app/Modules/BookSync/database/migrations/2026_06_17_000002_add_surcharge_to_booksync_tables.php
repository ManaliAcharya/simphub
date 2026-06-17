<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Merchant: surcharge configuration
        Schema::table('booksync_merchants', function (Blueprint $table): void {
            $table->boolean('surcharge_enabled')->default(false)->after('default_customer_name');
            $table->string('surcharge_item_id', 100)->nullable()->after('surcharge_enabled');
            $table->string('surcharge_item_name', 255)->nullable()->after('surcharge_item_id');
        });

        // Transaction: surcharge amount per transaction
        Schema::table('booksync_transactions', function (Blueprint $table): void {
            $table->decimal('surcharge_amount', 10, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('booksync_transactions', function (Blueprint $table): void {
            $table->dropColumn('surcharge_amount');
        });

        Schema::table('booksync_merchants', function (Blueprint $table): void {
            $table->dropColumn(['surcharge_enabled', 'surcharge_item_id', 'surcharge_item_name']);
        });
    }
};
