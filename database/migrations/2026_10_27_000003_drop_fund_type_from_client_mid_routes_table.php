<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lawcus picks its MID by invoice type (Gateway Credentials: operating vs IOLTA), not by
 * Multi-MID routes, so MID routes are QuickBooks-only again and no longer need a fund type.
 * Reverses 2026_10_27_000001; TRUST routes were only ever Lawcus ones and are dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('client_mid_routes', 'fund_type')) {
            return;
        }

        DB::table('client_mid_routes')->where('fund_type', 'TRUST')->delete();

        Schema::table('client_mid_routes', function (Blueprint $table): void {
            $table->dropUnique('cmr_client_fund_route_gateway_unique');
            $table->unique(['client_id', 'route_type', 'gateway'], 'cmr_client_route_gateway_unique');
        });

        Schema::table('client_mid_routes', function (Blueprint $table): void {
            $table->dropColumn('fund_type');
        });
    }

    public function down(): void
    {
        Schema::table('client_mid_routes', function (Blueprint $table): void {
            $table->string('fund_type', 20)->default('OPERATING')->after('client_id');
        });

        Schema::table('client_mid_routes', function (Blueprint $table): void {
            $table->dropUnique('cmr_client_route_gateway_unique');
            $table->unique(['client_id', 'fund_type', 'route_type', 'gateway'], 'cmr_client_fund_route_gateway_unique');
        });
    }
};
