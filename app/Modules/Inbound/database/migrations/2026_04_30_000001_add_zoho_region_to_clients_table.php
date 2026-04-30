<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('zoho_region', 10)->nullable()->after('client_pms');
        });

        DB::table('clients')
            ->where('client_pms', 'ZOHO')
            ->whereNull('zoho_region')
            ->update([
                'zoho_region' => 'US',
            ]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('zoho_region');
        });
    }
};
