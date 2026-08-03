<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('qb_payment_id', 100)->nullable()->after('fee_cents');
            $table->string('qb_journalentry_id', 100)->nullable()->after('qb_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn(['qb_payment_id', 'qb_journalentry_id']);
        });
    }
};
