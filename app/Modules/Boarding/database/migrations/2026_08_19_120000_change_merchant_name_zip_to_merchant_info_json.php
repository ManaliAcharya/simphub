<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boarding_merchants', function (Blueprint $table) {
            $table->json('merchant_info')->nullable()->after('merchant_ref');
        });

        Schema::table('boarding_merchants', function (Blueprint $table) {
            $table->dropColumn(['merchant_name', 'merchant_zip']);
        });
    }

    public function down(): void
    {
        Schema::table('boarding_merchants', function (Blueprint $table) {
            $table->string('merchant_name', 255)->nullable()->after('merchant_ref');
            $table->string('merchant_zip', 20)->nullable()->after('merchant_name');
        });

        Schema::table('boarding_merchants', function (Blueprint $table) {
            $table->dropColumn('merchant_info');
        });
    }
};
