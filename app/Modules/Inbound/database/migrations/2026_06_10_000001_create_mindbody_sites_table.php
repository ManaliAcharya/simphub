<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mindbody_sites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('pms_client_id', 36)->index();          // FK to clients.pms_client_id
            $table->string('site_id', 20);                          // Mindbody site/location ID
            $table->string('site_name')->nullable();
            $table->text('staff_username_encrypted')->nullable();    // encrypted
            $table->text('staff_password_encrypted')->nullable();    // encrypted
            $table->text('staff_token')->nullable();                 // current bearer token
            $table->timestamp('staff_token_expires_at')->nullable();
            $table->integer('custom_payment_method_id')->nullable(); // "Online Payment" method ID
            $table->string('custom_payment_method_name')->nullable();
            $table->string('payment_link_mode')->default('per_sale'); // per_sale | account_balance
            $table->string('webhook_subscription_id')->nullable();
            $table->text('webhook_signature_key_encrypted')->nullable(); // returned once on sub creation
            $table->boolean('webhook_active')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->unique(['pms_client_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mindbody_sites');
    }
};
