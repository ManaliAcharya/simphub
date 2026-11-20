<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The agent (users.id, role = agent) a client belongs to. Nullable — a
     * client doesn't have to have an agent, and existing clients start
     * unassigned. If the agent's user row is deleted the client is kept and
     * simply becomes unassigned (admins still see it).
     */
    public function up(): void
    {
        foreach (['clients', 'boarding_clients'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('agent_id')->nullable()->after('id')
                    ->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['clients', 'boarding_clients'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('agent_id');
            });
        }
    }
};
