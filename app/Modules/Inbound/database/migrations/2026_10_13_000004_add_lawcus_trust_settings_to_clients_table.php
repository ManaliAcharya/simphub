<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            // Lawcus bank account (type TRUST) that Trust Request payments are recorded to.
            $table->string('lawcus_trust_bank_account_id', 100)->nullable();
            $table->string('lawcus_trust_bank_account_name')->nullable();
            // Trust Request payment links stay unsent until the firm approves the wording.
            $table->boolean('lawcus_send_trust_links')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['lawcus_trust_bank_account_id', 'lawcus_trust_bank_account_name', 'lawcus_send_trust_links']);
        });
    }
};
