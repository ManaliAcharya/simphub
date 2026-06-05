<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_mid_routes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->string('route_type', 20);    // 'fees_on' | 'fees_off'
            $table->string('gateway', 50);        // 'fluidpay' | 'paya' | 'nmi'
            $table->string('mid_identifier', 100);
            $table->string('mid_label', 255)->nullable();
            $table->decimal('rate_percent', 5, 2)->nullable();
            $table->string('environment', 20)->default('sandbox');
            $table->text('credentials')->nullable(); // encrypted JSON
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->unique(['client_id', 'route_type', 'gateway'], 'cmr_client_route_gateway_unique');
            $table->index(['client_id', 'route_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_mid_routes');
    }
};
