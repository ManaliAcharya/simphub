<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignUuid('payment_session_id')->nullable()->change();
            $table->foreignUuid('invoice_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignUuid('payment_session_id')->nullable(false)->change();
            $table->foreignUuid('invoice_id')->nullable(false)->change();
        });
    }
};
