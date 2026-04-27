<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_sessions', function (Blueprint $table): void {
            $table->timestamp('payment_link_sent_at')->nullable()->after('completed_at');
            $table->json('payment_link_last_sent_to')->nullable()->after('payment_link_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'payment_link_sent_at',
                'payment_link_last_sent_to',
            ]);
        });
    }
};
