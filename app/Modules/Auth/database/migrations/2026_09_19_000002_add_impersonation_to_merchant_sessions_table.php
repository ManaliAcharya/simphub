<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('merchant_sessions', function (Blueprint $table) {
            $table->boolean('is_impersonation')->default(false)->after('revoked_reason');

            $table->foreignId('impersonated_by_user_id')
                ->nullable()
                ->after('is_impersonation')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('impersonated_by_user_id');
            $table->dropColumn('is_impersonation');
        });
    }
};
