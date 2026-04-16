<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routing_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('merchant_id', 100);
            $table->string('gateway', 50);
            $table->string('mid', 100);
            $table->text('mid_credentials')->comment('Encrypted via Laravel encrypt()');
            $table->string('payment_method', 20)->comment('CARD | ACH | ANY');
            $table->string('fund_type', 20)->comment('TRUST | OPERATING | ANY');
            $table->unsignedInteger('min_amount_cents')->default(0);
            $table->unsignedInteger('max_amount_cents')->nullable();
            $table->tinyInteger('priority')->default(10);
            $table->tinyInteger('is_fallback')->default(0);
            $table->unsignedBigInteger('daily_volume_limit_cents')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routing_rules');
    }
};