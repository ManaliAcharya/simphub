<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mindbody_sale_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('mindbody_site_id')->index();
            $table->string('sale_id', 50);                     // Mindbody SaleId
            $table->string('client_id_mb', 50);                // Mindbody ClientId
            $table->string('client_name')->nullable();
            $table->string('client_email')->nullable();
            $table->string('client_phone', 50)->nullable();
            $table->unsignedInteger('sale_amount_cents');       // original sale amount
            $table->string('fee_mode', 20)->nullable();         // surcharge | cash_discount
            $table->decimal('fee_percent', 5, 2)->nullable();
            $table->unsignedInteger('fee_amount_cents')->nullable();
            $table->unsignedInteger('total_charged_cents')->nullable();
            $table->text('payment_link_url')->nullable();
            $table->timestamp('payment_link_sent_at')->nullable();
            $table->string('payment_status', 20)->default('pending');
            // pending | sent | paid | failed | cancelled | refunded
            $table->string('gateway', 20)->nullable();
            $table->string('mid_identifier', 100)->nullable();
            $table->string('gateway_transaction_id')->nullable();
            $table->boolean('mb_payment_posted')->default(false);
            $table->timestamp('mb_payment_posted_at')->nullable();
            $table->json('raw_sale_payload')->nullable();       // original webhook payload
            $table->timestamps();

            $table->index(['sale_id', 'mindbody_site_id']);
            $table->index('payment_status');
            $table->index('client_id_mb');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mindbody_sale_links');
    }
};
