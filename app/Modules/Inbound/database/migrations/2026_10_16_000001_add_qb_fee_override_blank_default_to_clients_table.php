<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            // What a blank Per-Invoice Fee Override field means: 'absorb' (merchant pays the
            // fee, the behaviour so far) or 'pass' (customer pays it).
            $table->string('qb_fee_override_blank_default', 10)->default('absorb')->after('qb_fee_override_field');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('qb_fee_override_blank_default');
        });
    }
};
