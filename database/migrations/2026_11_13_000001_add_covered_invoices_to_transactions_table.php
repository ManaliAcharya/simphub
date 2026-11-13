<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            // Other invoices this charge also paid — the payer chose "all outstanding" on a
            // Lawcus invoice. [{external_invoice_id, invoice_number, amount_cents, matter_id}]
            $table->json('covered_invoices')->nullable()->after('billing_address');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn('covered_invoices');
        });
    }
};
