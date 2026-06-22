<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_ticket_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->onDelete('cascade');
            $table->foreignId('complex_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('from_ticket');
            $table->unsignedInteger('to_ticket');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['doctor_id', 'complex_id'], 'doctor_ticket_configs_doctor_complex_unique');
            $table->index(['complex_id', 'is_active'], 'doctor_ticket_configs_complex_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_ticket_configs');
    }
};
