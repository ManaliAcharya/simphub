<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boarding_link_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('boarding_merchant_id');
            $table->string('type', 32);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('boarding_merchant_id')->references('id')->on('boarding_merchants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boarding_link_events');
    }
};
