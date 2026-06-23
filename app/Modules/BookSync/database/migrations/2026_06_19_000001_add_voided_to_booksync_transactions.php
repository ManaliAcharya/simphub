<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE booksync_transactions
             MODIFY COLUMN status
             ENUM('queued','posted','already_posted','failed','permanently_failed','voided')
             NOT NULL DEFAULT 'queued'"
        );
    }

    public function down(): void
    {
        // This will fail if any row has status='voided'. Convert them before rolling back.
        DB::statement(
            "ALTER TABLE booksync_transactions
             MODIFY COLUMN status
             ENUM('queued','posted','already_posted','failed','permanently_failed')
             NOT NULL DEFAULT 'queued'"
        );
    }
};
