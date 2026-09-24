<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Superseded design change: "Ready to Send?" no longer reads a QB custom field, so
        // there's no per-invoice field-transition state left to track.
        Schema::table('payment_sessions', function (Blueprint $table): void {
            $table->dropColumn('ready_to_send_last_value');
        });
    }

    public function down(): void
    {
        Schema::table('payment_sessions', function (Blueprint $table): void {
            $table->string('ready_to_send_last_value', 10)->nullable()->after('next_reminder_at');
        });
    }
};
