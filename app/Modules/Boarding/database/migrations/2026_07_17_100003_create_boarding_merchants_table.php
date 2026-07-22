<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boarding_merchants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->string('processor', 32)->default('square');
            $table->string('scope', 32)->default('merchant');
            $table->enum('tier', ['rack_rate', 'flat_rate']);
            $table->string('agent_ref', 100);
            $table->string('merchant_ref', 100);
            $table->string('merchant_name', 255);
            $table->string('merchant_zip', 20)->nullable();
            $table->string('created_by', 100)->nullable();
            $table->string('token', 64)->unique();
            $table->enum('status', ['link_generated', 'clicked'])->default('link_generated');
            $table->timestamp('clicked_at')->nullable();
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('boarding_clients')->cascadeOnDelete();
            $table->unique(['client_id', 'merchant_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boarding_merchants');
    }
};
