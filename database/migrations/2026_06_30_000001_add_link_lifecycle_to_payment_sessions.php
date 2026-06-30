<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_sessions', function (Blueprint $table): void {
            $table->decimal('original_amount', 10, 2)->nullable()->after('fund_type');
            $table->string('link_status', 20)->default('active')->after('original_amount');
            $table->timestamp('last_email_sent_at')->nullable()->after('link_status');

            $table->index('link_status');
        });
    }

    public function down(): void
    {
        Schema::table('payment_sessions', function (Blueprint $table): void {
            $table->dropIndex(['link_status']);
            $table->dropColumn(['original_amount', 'link_status', 'last_email_sent_at']);
        });
    }
};
