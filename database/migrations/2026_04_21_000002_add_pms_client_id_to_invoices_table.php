<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->uuid('pms_client_id')->nullable()->after('pms_source');
            $table->dropUnique(['pms_source', 'external_invoice_id']);
            $table->unique(['pms_source', 'pms_client_id', 'external_invoice_id'], 'invoices_source_client_external_unique');
            $table->index('pms_client_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_source_client_external_unique');
            $table->dropIndex(['pms_client_id']);
            $table->dropColumn('pms_client_id');
            $table->unique(['pms_source', 'external_invoice_id']);
        });
    }
};
