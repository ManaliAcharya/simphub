<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boarding_master_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->string('processor', 32)->default('square');
            $table->enum('tier', ['rack_rate', 'flat_rate']);
            $table->string('url', 2048);
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('boarding_clients')->cascadeOnDelete();
            $table->unique(['client_id', 'processor', 'tier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boarding_master_links');
    }
};
