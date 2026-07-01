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
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();

            // Present for email-based attempts
            $table->string('email_lower')->nullable();

            // Supports IPv4 and IPv6
            $table->ipAddress('ip_address');

            // success | failure
            $table->enum('outcome', [
                'success',
                'failure',
            ]);

            $table->timestamp('attempted_at')->useCurrent();

            // Equivalent indexes
            $table->index(
                ['email_lower', 'attempted_at'],
                'idx_login_attempts_email'
            );

            $table->index(
                ['ip_address', 'attempted_at'],
                'idx_login_attempts_ip'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};
