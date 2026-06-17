<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booksync_clients', function (Blueprint $table) {
            $table->string('portal_password')->nullable()->after('contact_email');
        });
    }

    public function down(): void
    {
        Schema::table('booksync_clients', function (Blueprint $table) {
            $table->dropColumn('portal_password');
        });
    }
};
