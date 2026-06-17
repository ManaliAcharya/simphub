<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booksync_api_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Who made the request
            $table->uuid('client_id')->nullable()->index();        // null if auth failed
            $table->uuid('merchant_id')->nullable()->index();      // null if merchant not found

            // What was sent
            $table->string('ip_address', 45);
            $table->string('path', 500);
            $table->longText('request_body')->nullable();          // raw POST body
            $table->string('timestamp_header', 20)->nullable();    // X-BookSync-Timestamp
            $table->string('signature_header', 100)->nullable();   // X-BookSync-Signature

            // What happened
            $table->unsignedSmallInteger('http_status');
            $table->string('rejection_reason', 64)->nullable();    // null = success
            $table->string('batch_id', 100)->nullable();           // set on successful post

            $table->timestamp('created_at');

            $table->foreign('client_id')->references('id')->on('booksync_clients')->nullOnDelete();
            $table->foreign('merchant_id')->references('id')->on('booksync_merchants')->nullOnDelete();

            $table->index('created_at');
            $table->index(['client_id', 'created_at']);
            $table->index('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booksync_api_logs');
    }
};
