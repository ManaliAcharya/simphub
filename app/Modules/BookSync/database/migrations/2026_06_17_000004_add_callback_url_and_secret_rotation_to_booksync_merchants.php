<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booksync_merchants', function (Blueprint $table): void {
            $table->string('callback_url', 2048)->nullable()->after('status');
            $table->text('previous_signing_secret')->nullable()->after('signing_secret');
            $table->dateTime('previous_secret_expires_at')->nullable()->after('previous_signing_secret');
        });
    }

    public function down(): void
    {
        Schema::table('booksync_merchants', function (Blueprint $table): void {
            $table->dropColumn(['callback_url', 'previous_signing_secret', 'previous_secret_expires_at']);
        });
    }
};
