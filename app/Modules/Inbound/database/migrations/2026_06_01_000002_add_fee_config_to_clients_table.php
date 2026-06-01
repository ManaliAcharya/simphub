<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->decimal('cc_fee_percent', 5, 2)->nullable()->after('logo_path');
            $table->decimal('ach_fee_percent', 5, 2)->nullable()->after('cc_fee_percent');
            $table->boolean('fee_surcharge_enabled')->default(false)->after('ach_fee_percent');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['cc_fee_percent', 'ach_fee_percent', 'fee_surcharge_enabled']);
        });
    }
};
