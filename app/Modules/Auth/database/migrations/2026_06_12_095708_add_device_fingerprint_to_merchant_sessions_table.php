<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_sessions', function (Blueprint $table) {
            $table->string('device_fingerprint', 64)
                ->nullable()
                ->after('user_agent');

            $table->index(
                ['client_account_id', 'device_fingerprint', 'created_at'],
                'idx_merchant_sessions_device'
            );
        });
    }

    public function down(): void
    {
        Schema::table('merchant_sessions', function (Blueprint $table) {
            $table->dropIndex('idx_merchant_sessions_device');
            $table->dropColumn('device_fingerprint');
        });
    }
};
