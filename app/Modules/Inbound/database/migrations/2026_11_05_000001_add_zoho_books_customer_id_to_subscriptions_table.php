<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            // The customer's Zoho Books contact, found or created on the first synced payment.
            $table->string('zoho_books_customer_id', 64)->nullable()->after('customer_unique_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('zoho_books_customer_id');
        });
    }
};
