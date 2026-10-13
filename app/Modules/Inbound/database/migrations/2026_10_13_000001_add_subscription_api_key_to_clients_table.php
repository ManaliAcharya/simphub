<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only a SHA-256 hash of the key is stored; the plain key is shown once when generated.
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('subscription_api_key_hash', 64)->nullable()->unique();
            $table->string('subscription_api_key_last4', 4)->nullable();
            $table->timestamp('subscription_api_key_created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropUnique(['subscription_api_key_hash']);
            $table->dropColumn([
                'subscription_api_key_hash',
                'subscription_api_key_last4',
                'subscription_api_key_created_at',
            ]);
        });
    }
};
