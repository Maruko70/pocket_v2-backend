<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_weekly_schedule', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->onDelete('cascade');
            $table->foreignId('complex_id')->constrained()->onDelete('cascade');
            $table->tinyInteger('day_of_week'); // 0 = Sunday, 1 = Monday, ..., 6 = Saturday
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            // Unique constraint: one doctor can't have duplicate time slots for same day/complex
            $table->unique(['doctor_id', 'complex_id', 'day_of_week', 'start_time', 'end_time'], 'unique_doctor_schedule');
            $table->index(['doctor_id', 'day_of_week', 'is_active'], 'idx_schedule_doctor_day_active');
            $table->index(['complex_id', 'day_of_week'], 'idx_schedule_complex_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_weekly_schedule');
    }
};

