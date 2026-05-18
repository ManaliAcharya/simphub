<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // 'debit' = payment charge, 'credit' = refund
            $table->string('transaction_type', 10)->default('debit')->after('gateway_response');
            // Links a refund transaction back to the original captured transaction
            $table->foreignUuid('parent_transaction_id')->nullable()->after('transaction_type')
                ->references('id')->on('transactions');

            $table->index('transaction_type');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['transaction_type']);
            $table->dropForeign(['parent_transaction_id']);
            $table->dropColumn(['transaction_type', 'parent_transaction_id']);
        });
    }
};
