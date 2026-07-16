<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advancedmd_practices', function (Blueprint $table): void {
            $table->unsignedInteger('consecutive_poll_failures')->default(0)->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('advancedmd_practices', function (Blueprint $table): void {
            $table->dropColumn('consecutive_poll_failures');
        });
    }
};
