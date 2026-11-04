<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Ready to Send?" grows from an on/off switch into three modes: auto (save sends,
        // as before), trigger (a QB custom field starts sending) and manual (Send button).
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('ready_to_send_mode', 10)->default('auto')->after('ready_to_send_enabled');
            $table->string('ready_to_send_field', 100)->nullable()->default('Ready to Send')->after('ready_to_send_mode');
            // Trigger-Send reads the Per-Invoice Override field instead of its own, so the
            // two names can't drift apart.
            $table->boolean('ready_to_send_use_override_field')->default(false)->after('ready_to_send_field');
        });

        // Clients who had it switched on were on today's manual Send button.
        DB::table('clients')->where('ready_to_send_enabled', true)->update(['ready_to_send_mode' => 'manual']);

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('ready_to_send_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->boolean('ready_to_send_enabled')->default(false)->after('qb_multi_mid_enabled');
        });

        // Trigger has no on/off equivalent; manual is the safe side, it never sends unasked.
        DB::table('clients')->whereIn('ready_to_send_mode', ['manual', 'trigger'])->update(['ready_to_send_enabled' => true]);

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['ready_to_send_mode', 'ready_to_send_field', 'ready_to_send_use_override_field']);
        });
    }
};
