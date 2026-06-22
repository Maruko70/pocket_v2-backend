<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('doctor_id')->constrained()->onDelete('cascade');
            $table->foreignId('complex_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->time('time');
            $table->string('patient_name');
            $table->string('patient_phone');
            $table->enum('status', ['pending', 'confirmed', 'cancelled', 'completed'])->default('pending');
            $table->timestamps();
            
            $table->index(['user_id', 'status'], 'idx_reservations_user_status');
            $table->index(['doctor_id', 'date', 'time'], 'idx_reservations_doctor_datetime');
            $table->index(['date', 'status'], 'idx_reservations_date_status');
            $table->index(['complex_id', 'date'], 'idx_reservations_complex_date');
            $table->index('status', 'idx_reservations_status');
            $table->index('date', 'idx_reservations_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};

