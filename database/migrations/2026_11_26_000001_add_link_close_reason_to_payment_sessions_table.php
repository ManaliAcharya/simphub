<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_sessions', function (Blueprint $table): void {
            // Why the payment link was closed (see PaymentLinkClosure) and when. A link closed
            // because the PMS recorded a payment can be reopened when a balance comes back;
            // one paid through SimpHub never is.
            $table->string('link_closed_reason', 40)->nullable()->after('link_status');
            $table->timestamp('link_closed_at')->nullable()->after('link_closed_reason');
        });

        // Backfill only reasons that are certain from existing data; anything else stays
        // null, and a null reason is never reopened automatically.
        $paidThroughUs = DB::table('transactions')
            ->select('invoice_id')
            ->where('status', '!=', 'FAILED');

        DB::table('payment_sessions')->where('link_status', 'voided')
            ->update(['link_closed_reason' => 'voided_in_pms']);

        DB::table('payment_sessions')->where('link_status', 'disabled')
            ->update(['link_closed_reason' => 'removed_in_pms']);

        DB::table('payment_sessions')->where('link_status', 'paid')->where('status', 'COMPLETED')
            ->update(['link_closed_reason' => 'paid_via_simphub']);

        // QuickBooks links closed by a payment recorded in QuickBooks (ProcessQBInvoiceLinkJob).
        // No SimpHub payment on the invoice, so the payment can only have come from QuickBooks.
        DB::table('payment_sessions')
            ->where('link_status', 'paid')
            ->where('status', '!=', 'COMPLETED')
            ->whereNull('link_closed_reason')
            ->whereIn('invoice_id', DB::table('invoices')->select('id')->where('pms_source', 'quickbooks'))
            ->whereNotIn('invoice_id', $paidThroughUs)
            ->update(['link_closed_reason' => 'paid_in_pms']);
    }

    public function down(): void
    {
        Schema::table('payment_sessions', function (Blueprint $table): void {
            $table->dropColumn(['link_closed_reason', 'link_closed_at']);
        });
    }
};
