<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booksync_merchants', function (Blueprint $table): void {
            $table->text('signing_secret')->nullable()->after('posting_token');
        });
    }

    public function down(): void
    {
        Schema::table('booksync_merchants', function (Blueprint $table): void {
            $table->dropColumn('signing_secret');
        });
    }
};
