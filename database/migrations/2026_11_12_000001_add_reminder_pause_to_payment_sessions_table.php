<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_sessions', function (Blueprint $table): void {
            // Staff paused reminders from the invoice list (e.g. "check is in the mail").
            $table->timestamp('reminders_paused_at')->nullable()->after('next_reminder_at');
            // Resume restarts the reminder schedule: day offsets count from this moment instead
            // of first_email_sent_at, and from this many reminders already sent.
            $table->timestamp('reminder_cycle_started_at')->nullable()->after('reminders_paused_at');
            $table->unsignedInteger('reminder_cycle_offset')->default(0)->after('reminder_cycle_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_sessions', function (Blueprint $table): void {
            $table->dropColumn(['reminders_paused_at', 'reminder_cycle_started_at', 'reminder_cycle_offset']);
        });
    }
};
