<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('payment_link_recipient', 20)->default('customer')->after('logo_path');
            $table->string('payment_link_admin_email', 255)->nullable()->after('payment_link_recipient');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['payment_link_recipient', 'payment_link_admin_email']);
        });
    }
};
