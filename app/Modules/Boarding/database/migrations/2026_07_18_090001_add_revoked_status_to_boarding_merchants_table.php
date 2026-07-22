<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boarding_merchants', function (Blueprint $table) {
            $table->enum('status', ['link_generated', 'clicked', 'revoked'])
                ->default('link_generated')
                ->change();
            $table->timestamp('revoked_at')->nullable()->after('clicked_at');
        });
    }

    public function down(): void
    {
        Schema::table('boarding_merchants', function (Blueprint $table) {
            $table->dropColumn('revoked_at');
            $table->enum('status', ['link_generated', 'clicked'])
                ->default('link_generated')
                ->change();
        });
    }
};
