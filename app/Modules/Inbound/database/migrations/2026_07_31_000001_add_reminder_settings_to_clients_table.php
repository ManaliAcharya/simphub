<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->boolean('reminders_enabled')->default(false)->after('auto_resend_on_change');
            $table->json('reminder_schedule_days')->nullable()->after('reminders_enabled');
            $table->string('reminder_subject_template', 500)
                ->default('Reminder: Invoice {invoice_number} is awaiting payment')
                ->after('reminder_schedule_days');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['reminders_enabled', 'reminder_schedule_days', 'reminder_subject_template']);
        });
    }
};
