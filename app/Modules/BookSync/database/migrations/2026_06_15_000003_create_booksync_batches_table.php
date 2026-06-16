<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booksync_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('batch_id', 64)->unique();
            $table->uuid('merchant_id');
            $table->date('batch_date');
            $table->unsignedInteger('total_transactions')->default(0);
            $table->unsignedInteger('posted')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('queued')->default(0);
            $table->enum('status', ['processing', 'completed', 'partial'])->default('processing');
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('booksync_merchants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booksync_batches');
    }
};
