<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booksync_clients', function (Blueprint $table) {
            $table->string('accounting_system', 64)->default('quickbooks')->after('contact_email');
        });
    }

    public function down(): void
    {
        Schema::table('booksync_clients', function (Blueprint $table) {
            $table->dropColumn('accounting_system');
        });
    }
};
