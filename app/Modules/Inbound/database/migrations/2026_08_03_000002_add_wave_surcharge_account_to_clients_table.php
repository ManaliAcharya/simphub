<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->boolean('wave_surcharge_enabled')->default(false)->after('wave_default_account_name');
            $table->string('wave_surcharge_account_id')->nullable()->after('wave_surcharge_enabled');
            $table->string('wave_surcharge_account_name')->nullable()->after('wave_surcharge_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['wave_surcharge_enabled', 'wave_surcharge_account_id', 'wave_surcharge_account_name']);
        });
    }
};
