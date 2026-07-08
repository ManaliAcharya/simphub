<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advancedmd_practices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('pms_client_id', 36)->index();
            $table->string('office_key', 20);
            $table->string('app_name', 50);
            $table->text('username_encrypted');
            $table->text('password_encrypted');
            $table->text('session_token')->nullable();
            $table->timestamp('session_expires_at')->nullable();
            $table->string('xmlrpc_url')->nullable();
            $table->string('rest_pm_url')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->unique(['pms_client_id', 'office_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advancedmd_practices');
    }
};
