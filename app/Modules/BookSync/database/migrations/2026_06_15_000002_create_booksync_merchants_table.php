<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booksync_merchants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->string('name', 255);
            $table->string('merchant_id', 64)->unique();
            $table->string('external_merchant_id', 100)->nullable();
            $table->string('merchant_email', 255)->nullable();
            $table->string('setup_token', 64)->unique();
            $table->string('posting_token', 64)->unique()->nullable();
            $table->string('qb_realm_id', 64)->nullable();
            $table->string('qb_company_name', 255)->nullable();
            $table->text('qb_access_token')->nullable();
            $table->text('qb_refresh_token')->nullable();
            $table->timestamp('qb_token_expires_at')->nullable();
            $table->string('deposit_account_id', 64)->nullable();
            $table->string('deposit_account_name', 255)->nullable();
            $table->timestamp('qb_connected_at')->nullable();
            $table->enum('status', ['pending_qb_connect', 'active', 'qb_token_expired', 'disabled'])
                  ->default('pending_qb_connect');
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('booksync_clients')->cascadeOnDelete();
            $table->index(['client_id', 'external_merchant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booksync_merchants');
    }
};
