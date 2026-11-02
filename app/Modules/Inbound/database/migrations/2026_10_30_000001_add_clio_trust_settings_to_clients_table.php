<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            // Clio bank account (type TrustAccount) that Trust Request payments are deposited to.
            $table->string('clio_trust_bank_account_id', 100)->nullable();
            $table->string('clio_trust_bank_account_name')->nullable();
            // Trust Request payment links stay unsent until the firm approves the wording.
            $table->boolean('clio_send_trust_links')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['clio_trust_bank_account_id', 'clio_trust_bank_account_name', 'clio_send_trust_links']);
        });
    }
};
