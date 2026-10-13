<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Day of the month (1-31) monthly subscription links go out; null means the 1st.
        Schema::table('clients', function (Blueprint $table): void {
            $table->unsignedTinyInteger('subscription_billing_day')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('subscription_billing_day');
        });
    }
};
