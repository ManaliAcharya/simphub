<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('boarding_clients', function (Blueprint $table) {
            $table->json('allowed_processors')->nullable()->after('webhook_secret');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('boarding_clients', function (Blueprint $table) {
            $table->dropColumn('allowed_processors');
        });
    }
};
