<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booksync_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id');
            $table->uuid('merchant_id');
            $table->string('reference', 100);
            $table->string('customer_name', 255);
            $table->string('customer_email', 255)->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('payment_method', 50)->default('Other');
            $table->date('transaction_date');
            $table->string('memo', 500)->nullable();
            $table->enum('status', ['queued', 'posted', 'already_posted', 'failed', 'permanently_failed'])->default('queued');
            $table->string('qb_salesreceipt_id', 64)->nullable();
            $table->string('qb_customer_id', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->foreign('batch_id')->references('id')->on('booksync_batches')->cascadeOnDelete();
            $table->foreign('merchant_id')->references('id')->on('booksync_merchants')->cascadeOnDelete();
            $table->unique(['merchant_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booksync_transactions');
    }
};
