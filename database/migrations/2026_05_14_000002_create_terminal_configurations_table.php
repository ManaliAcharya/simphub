<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_configurations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('merchant_id', 100)->index();
            $table->string('terminal', 50);
            $table->text('terminal_credentials')->comment('Encrypted via Laravel encrypt()');
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_configurations');
    }
};
