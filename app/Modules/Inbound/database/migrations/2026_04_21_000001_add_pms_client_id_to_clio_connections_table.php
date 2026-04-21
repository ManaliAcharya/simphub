<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clio_connections', function (Blueprint $table): void {
            $table->uuid('pms_client_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('clio_connections', function (Blueprint $table): void {
            $table->dropUnique(['pms_client_id']);
            $table->dropColumn('pms_client_id');
        });
    }
};
