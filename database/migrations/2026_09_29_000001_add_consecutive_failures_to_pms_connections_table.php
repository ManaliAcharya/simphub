<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pms_connections', function (Blueprint $table): void {
            // Tracks consecutive auth-failure detections against this connection (currently
            // used for Lawcus's manually-pasted-token alerting; harmless/unused default for
            // every other provider on this shared table).
            $table->unsignedInteger('consecutive_failures')->default(0)->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('pms_connections', function (Blueprint $table): void {
            $table->dropColumn('consecutive_failures');
        });
    }
};
