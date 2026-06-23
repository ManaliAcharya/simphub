<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booksync_merchants', function (Blueprint $table): void {
            $table->string('callback_url_success', 2048)->nullable()->after('callback_url');
            $table->string('callback_url_fail', 2048)->nullable()->after('callback_url_success');
        });
    }

    public function down(): void
    {
        Schema::table('booksync_merchants', function (Blueprint $table): void {
            $table->dropColumn(['callback_url_success', 'callback_url_fail']);
        });
    }
};
