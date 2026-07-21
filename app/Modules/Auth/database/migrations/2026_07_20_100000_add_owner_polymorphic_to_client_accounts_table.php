<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->string('owner_type')->nullable()->after('client_id');
            $table->uuid('owner_id')->nullable()->after('owner_type');
        });

        DB::table('client_accounts')->whereNull('owner_id')->update([
            'owner_type' => 'Modules\\Inbound\\Models\\Client',
            'owner_id' => DB::raw('client_id'),
        ]);

        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropUnique(['client_id']);
            $table->dropColumn('client_id');

            $table->unique(['owner_type', 'owner_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->uuid('client_id')->nullable()->after('id');
        });

        DB::table('client_accounts')
            ->where('owner_type', 'Modules\\Inbound\\Models\\Client')
            ->update(['client_id' => DB::raw('owner_id')]);

        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropUnique(['owner_type', 'owner_id']);
            $table->dropColumn(['owner_type', 'owner_id']);

            $table->uuid('client_id')->nullable(false)->unique()->change();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });
    }
};
