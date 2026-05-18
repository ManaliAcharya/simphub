<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('invoice_number', 64)->nullable()->after('external_invoice_id');
            $table->text('description')->nullable()->after('invoice_number');
            $table->json('customer')->nullable()->after('description');
            $table->string('success_redirect_url')->nullable()->after('customer');
            $table->string('cancel_redirect_url')->nullable()->after('success_redirect_url');
            $table->string('webhook_url')->nullable()->after('cancel_redirect_url');
            $table->json('metadata')->nullable()->after('webhook_url');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_number', 'description', 'customer',
                'success_redirect_url', 'cancel_redirect_url',
                'webhook_url', 'metadata',
            ]);
        });
    }
};
