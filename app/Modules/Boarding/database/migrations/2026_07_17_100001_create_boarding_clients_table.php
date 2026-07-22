<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boarding_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('client_id', 64)->unique();
            $table->text('client_api_key');
            $table->text('portal_password')->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('webhook_url', 2048)->nullable();
            $table->text('webhook_secret')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boarding_clients');
    }
};
