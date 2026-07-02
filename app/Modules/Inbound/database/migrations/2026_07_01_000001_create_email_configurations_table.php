<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_configurations', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_id')->unique();
            $table->string('subject_template', 500)->default('Invoice #{invoice_number} – Payment Required');
            $table->string('subject_template_updated', 500)->default('Updated: Invoice #{invoice_number} – Payment Required');
            $table->text('body_header')->nullable();
            $table->text('body_footer')->nullable();
            $table->string('logo_url', 1000)->nullable();
            $table->string('primary_color', 7)->default('#2196F3');
            $table->string('reply_to_email', 255)->nullable();
            $table->string('reply_to_name', 255)->nullable();
            $table->boolean('attach_pdf')->default(true);
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_configurations');
    }
};
