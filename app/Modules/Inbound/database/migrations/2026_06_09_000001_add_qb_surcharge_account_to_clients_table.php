<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('qb_surcharge_account_id')->nullable()->after('qb_default_account_name');
            $table->string('qb_surcharge_account_name')->nullable()->after('qb_surcharge_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['qb_surcharge_account_id', 'qb_surcharge_account_name']);
        });
    }
};
