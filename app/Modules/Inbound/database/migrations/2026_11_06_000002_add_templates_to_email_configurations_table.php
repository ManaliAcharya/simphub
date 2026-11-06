<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_configurations', function (Blueprint $table) {
            // Per-trigger templates for law firms, keyed by kind (flat_fee_invoice,
            // retainer_request) — see PaymentEmailTemplate. The base columns stay the
            // billable invoice template.
            $table->json('templates')->nullable()->after('attach_pdf');
        });
    }

    public function down(): void
    {
        Schema::table('email_configurations', function (Blueprint $table) {
            $table->dropColumn('templates');
        });
    }
};
