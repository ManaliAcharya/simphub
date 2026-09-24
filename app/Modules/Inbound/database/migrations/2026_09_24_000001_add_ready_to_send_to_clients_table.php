<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->boolean('ready_to_send_enabled')->default(false)->after('qb_multi_mid_enabled');
            $table->string('ready_to_send_field', 100)->nullable()->default('Ready to Send')->after('ready_to_send_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['ready_to_send_enabled', 'ready_to_send_field']);
        });
    }
};
