<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            // Where the fee charged on top of the invoice ended up in the PMS:
            // SPLIT | NOT_SPLIT | NOT_RECORDED | FIXED. Null when no fee was charged or the
            // payment hasn't been recorded in the PMS. See `php artisan fees:unsynced`.
            $table->string('fee_sync_status', 20)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex(['fee_sync_status']);
            $table->dropColumn('fee_sync_status');
        });
    }
};
