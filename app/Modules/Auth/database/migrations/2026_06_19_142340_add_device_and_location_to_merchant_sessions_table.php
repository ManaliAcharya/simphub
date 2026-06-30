<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_sessions', function (Blueprint $table) {

            $table->string('browser')
                ->nullable()
                ->after('user_agent');

            $table->string('platform')
                ->nullable()
                ->after('browser');

            $table->string('location')
                ->nullable()
                ->after('platform');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_sessions', function (Blueprint $table) {

            $table->dropColumn([
                'browser',
                'platform',
                'location',
            ]);
        });
    }
};
