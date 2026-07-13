<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pms_feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 50);
            $table->string('feature', 100);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['provider', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pms_feature_flags');
    }
};
