<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_session_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('payment_session_id')->constrained('payment_sessions')->cascadeOnDelete();
            $table->unsignedInteger('reminder_number');
            $table->unsignedInteger('day_offset');
            $table->enum('status', ['sent', 'skipped', 'failed']);
            $table->string('reason', 255)->nullable();
            $table->string('recipient', 50)->nullable();
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamps();

            $table->unique(['payment_session_id', 'reminder_number'], 'uniq_session_reminder_step');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_session_reminders');
    }
};
