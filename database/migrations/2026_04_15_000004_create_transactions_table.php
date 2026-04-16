<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_session_id')->constrained('payment_sessions');
            $table->foreignUuid('invoice_id')->constrained('invoices');
            $table->foreignUuid('routing_rule_id')->constrained('routing_rules');
            $table->string('gateway', 50);
            $table->string('mid', 100);
            $table->string('gateway_txn_id', 255)->nullable();
            $table->string('gateway_token', 500);
            $table->string('status', 30);
            $table->string('fund_type', 20);
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3);
            $table->tinyInteger('attempt_number')->default(1);
            $table->json('gateway_response')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('gateway_txn_id');
            $table->index('payment_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};