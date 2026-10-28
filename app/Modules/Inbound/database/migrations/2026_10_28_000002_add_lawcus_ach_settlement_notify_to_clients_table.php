<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            // Lawcus ACH: tell the firm when the payment settles (and when it's returned)
            // instead of at submit. Off = notified at submit, as before.
            $table->boolean('lawcus_ach_settlement_notify')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('lawcus_ach_settlement_notify');
        });
    }
};
