<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('invoices');
            $table->string('status', 30);
            $table->string('payment_method', 20)->nullable()->comment('CARD | ACH');
            $table->string('fund_type', 20);
            $table->string('idempotency_key', 255)->unique();
            $table->uuid('hosted_url_token')->unique();
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('expires_at');
            $table->index('hosted_url_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_sessions');
    }
};