<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {

            $table->uuid('id')->primary();
            $table->uuid('client_account_id');
            $table->text('code_hash');
            $table->timestamp('expires_at');

            $table->unsignedInteger('attempts')
                ->default(0);

            $table->timestamp('consumed_at')
                ->nullable();

            $table->string('requested_from_ip', 45);

            $table->timestamp('created_at')
                ->useCurrent();

            $table->foreign('client_account_id')
                ->references('id')
                ->on('client_accounts')
                ->cascadeOnDelete();

            $table->index(
                ['client_account_id', 'expires_at'],
                'idx_prt_active'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
