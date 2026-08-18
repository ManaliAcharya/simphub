<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_configurations', function (Blueprint $table) {
            $table->string('from_name', 255)->nullable()->after('reply_to_name');
        });
    }

    public function down(): void
    {
        Schema::table('email_configurations', function (Blueprint $table) {
            $table->dropColumn('from_name');
        });
    }
};
