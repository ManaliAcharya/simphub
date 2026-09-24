<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_sessions', function (Blueprint $table): void {
            // Last-seen normalized value ('yes' or null) of the client's "Ready to Send?"
            // custom field — lets ProcessQBInvoiceLinkJob tell a genuine blank/No -> Yes
            // transition apart from "still Yes, already sent, nothing to do."
            $table->string('ready_to_send_last_value', 10)->nullable()->after('next_reminder_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_sessions', function (Blueprint $table): void {
            $table->dropColumn('ready_to_send_last_value');
        });
    }
};
