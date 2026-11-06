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
            // The IOLTA tab's own fee % for Lawcus/Clio trust payments: the IOLTA MID may be
            // priced differently (e.g. interchange-plus, no fee passed on) from the operating MID.
            $table->decimal('trust_cc_fee_percent', 8, 5)->nullable()->after('ach_fee_percent');
            $table->decimal('trust_ach_fee_percent', 8, 5)->nullable()->after('trust_cc_fee_percent');
        });

        // Trust payments keep today's fee until someone changes the IOLTA tab.
        DB::table('clients')
            ->whereRaw('UPPER(client_pms) IN (?, ?)', ['LAWCUS', 'CLIO'])
            ->update([
                'trust_cc_fee_percent'  => DB::raw('cc_fee_percent'),
                'trust_ach_fee_percent' => DB::raw('ach_fee_percent'),
            ]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['trust_cc_fee_percent', 'trust_ach_fee_percent']);
        });
    }
};
