<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('reference_id', 32)->nullable()->unique();
            $table->uuid('pms_client_id')->index();
            $table->string('customer_email');
            $table->string('customer_name')->nullable();
            $table->string('customer_unique_id')->nullable();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('USD');
            $table->string('billing_cycle', 20)->default('monthly');
            $table->string('status', 20)->default('pending_payment');
            $table->date('next_billing_on')->nullable();
            $table->timestamp('last_billed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->date('effective_date')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_billing_on']);
            $table->index(['pms_client_id', 'customer_unique_id']);
            $table->index(['pms_client_id', 'customer_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
