<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_type', 100);
            $table->string('entity_type', 50);
            $table->uuid('entity_id');
            $table->string('actor_type', 30);
            $table->json('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('trace_id', 100);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};