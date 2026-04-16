<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 255)->unique();
            $table->string('action', 100);
            $table->string('response_status', 30);
            $table->foreignUuid('payment_session_id')->constrained('payment_sessions');
            $table->foreignUuid('transaction_id')->nullable()->constrained('transactions');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};