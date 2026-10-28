<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            // ACH settles days after it's submitted and can still come back returned.
            // PENDING | SETTLED | RETURNED; null for card payments. See PollAchSettlementsJob.
            $table->string('settlement_status', 20)->nullable()->index();
            $table->timestamp('settlement_checked_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->string('return_code', 20)->nullable();
            $table->string('return_reason', 255)->nullable();
            // When the firm was told about this payment ("You got paid"); held for ACH until it settles.
            $table->timestamp('merchant_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex(['settlement_status']);
            $table->dropColumn([
                'settlement_status', 'settlement_checked_at', 'settled_at', 'returned_at',
                'return_code', 'return_reason', 'merchant_notified_at',
            ]);
        });
    }
};
