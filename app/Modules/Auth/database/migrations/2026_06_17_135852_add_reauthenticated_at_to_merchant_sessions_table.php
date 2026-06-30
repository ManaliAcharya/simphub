<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_sessions', function (Blueprint $table) {
            $table->timestamp('reauthenticated_at')->nullable()->after('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_sessions', function (Blueprint $table) {
            $table->dropColumn('reauthenticated_at');
        });
    }
};
