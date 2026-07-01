<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_audits', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_account_id')->nullable();

            $table->string('event_type', 100);
            $table->string('outcome', 20);
            $table->string('failure_reason', 100)->nullable();

            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();

            $table->string('request_id', 100)->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->foreign('client_account_id')
                ->references('id')
                ->on('client_accounts')
                ->nullOnDelete();

            $table->index(
                ['client_account_id', 'created_at'],
                'idx_auth_audit_account_time'
            );

            $table->index(
                ['event_type', 'created_at'],
                'idx_auth_audit_event'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_audits');
    }
};
