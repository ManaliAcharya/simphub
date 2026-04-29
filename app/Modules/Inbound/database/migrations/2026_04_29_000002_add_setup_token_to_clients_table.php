<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('setup_token', 32)->nullable()->unique()->after('pms_client_id');
        });

        DB::table('clients')
            ->select('id')
            ->orderBy('created_at')
            ->get()
            ->each(function (object $client): void {
                DB::table('clients')
                    ->where('id', $client->id)
                    ->update([
                        'setup_token' => strtolower(Str::random(12)),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropUnique(['setup_token']);
            $table->dropColumn('setup_token');
        });
    }
};
