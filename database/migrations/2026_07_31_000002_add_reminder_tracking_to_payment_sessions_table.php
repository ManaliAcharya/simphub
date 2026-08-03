<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_sessions', function (Blueprint $table): void {
            $table->timestamp('first_email_sent_at')->nullable()->after('last_email_sent_at');
            $table->unsignedInteger('reminders_sent_count')->default(0)->after('first_email_sent_at');
            $table->timestamp('last_reminder_sent_at')->nullable()->after('reminders_sent_count');
            $table->timestamp('next_reminder_at')->nullable()->after('last_reminder_sent_at');

            $table->index(['link_status', 'next_reminder_at'], 'idx_payment_sessions_next_reminder');
        });
    }

    public function down(): void
    {
        Schema::table('payment_sessions', function (Blueprint $table): void {
            $table->dropIndex('idx_payment_sessions_next_reminder');
            $table->dropColumn(['first_email_sent_at', 'reminders_sent_count', 'last_reminder_sent_at', 'next_reminder_at']);
        });
    }
};
