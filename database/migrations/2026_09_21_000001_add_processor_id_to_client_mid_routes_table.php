<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_mid_routes', function (Blueprint $table): void {
            $table->string('processor_id', 100)->nullable()->after('mid_identifier');
        });
    }

    public function down(): void
    {
        Schema::table('client_mid_routes', function (Blueprint $table): void {
            $table->dropColumn('processor_id');
        });
    }
};
