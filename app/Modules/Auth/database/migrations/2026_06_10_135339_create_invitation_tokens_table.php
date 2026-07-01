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
        Schema::create('invitation_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_account_id');
            $table->string('token_hash')->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->uuid('created_by_admin_id')->nullable();
            $table->timestamps();

            $table->foreign('client_account_id')->references('id')->on('client_accounts')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invitation_tokens');
    }
};
