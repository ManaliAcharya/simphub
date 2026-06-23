<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booksync_refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id');
            $table->string('reference', 100);
            $table->string('original_reference', 100)->nullable()->comment('Links to booksync_transactions.reference');
            $table->string('customer_name', 255)->default('Walk-in');
            $table->string('customer_email', 255)->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('payment_method', 50)->default('Other');
            $table->date('transaction_date');
            $table->string('memo', 500)->nullable();
            $table->enum('status', ['queued', 'posted', 'failed'])->default('queued');
            $table->string('qb_refundreceipt_id', 64)->nullable();
            $table->string('qb_customer_id', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'reference']);
            $table->foreign('merchant_id')->references('id')->on('booksync_merchants')->cascadeOnDelete();
            $table->index(['merchant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booksync_refunds');
    }
};
