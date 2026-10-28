<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lawcus Multi-MID routes trust requests separately from regular invoices, so a route is
 * now keyed by fund type as well. Existing (QuickBooks) routes are all OPERATING.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_mid_routes', function (Blueprint $table): void {
            $table->string('fund_type', 20)->default('OPERATING')->after('client_id'); // 'OPERATING' | 'TRUST'
        });

        Schema::table('client_mid_routes', function (Blueprint $table): void {
            $table->dropUnique('cmr_client_route_gateway_unique');
            $table->unique(['client_id', 'fund_type', 'route_type', 'gateway'], 'cmr_client_fund_route_gateway_unique');
        });
    }

    public function down(): void
    {
        Schema::table('client_mid_routes', function (Blueprint $table): void {
            $table->dropUnique('cmr_client_fund_route_gateway_unique');
        });

        DB::table('client_mid_routes')->where('fund_type', 'TRUST')->delete();

        Schema::table('client_mid_routes', function (Blueprint $table): void {
            $table->unique(['client_id', 'route_type', 'gateway'], 'cmr_client_route_gateway_unique');
            $table->dropColumn('fund_type');
        });
    }
};
