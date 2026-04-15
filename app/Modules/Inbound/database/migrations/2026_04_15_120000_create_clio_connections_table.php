<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clio_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->unique()->default('clio');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('webhook_id')->nullable();
            $table->string('webhook_url')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->timestamp('webhook_expires_at')->nullable();
            $table->json('meta')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clio_connections');
    }
};
