<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('pms_source', 50)->comment('clio | lawpay');
            $table->string('external_invoice_id', 255);
            $table->string('external_client_id', 255);
            $table->string('external_matter_id', 255)->nullable();
            $table->string('status', 30)->default('PENDING');
            $table->string('fund_type', 20)->comment('TRUST | OPERATING');
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('USD');
            $table->string('pms_sync_status', 30)->default('PENDING_SYNC');
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['pms_source', 'external_invoice_id']);
            $table->index('status');
            $table->index('pms_sync_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};