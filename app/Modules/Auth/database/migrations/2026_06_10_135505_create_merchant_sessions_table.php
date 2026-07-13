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
        Schema::create('merchant_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_account_id');
            $table->string('session_token_hash')->unique();
            $table->timestamp('last_activity_at');
            $table->timestamp('absolute_expires_at')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->timestamps();

            $table->foreign('client_account_id')->references('id')->on('client_accounts')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_sessions');
    }
};
