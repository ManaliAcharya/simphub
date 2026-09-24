<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Superseded design change: "Ready to Send?" no longer reads a QB custom field —
        // sending is manual from the Invoice List tab instead. ready_to_send_enabled stays.
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('ready_to_send_field');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('ready_to_send_field', 100)->nullable()->default('Ready to Send')->after('ready_to_send_enabled');
        });
    }
};
