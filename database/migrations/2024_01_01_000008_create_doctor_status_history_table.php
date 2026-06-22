<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['active', 'suspended', 'disabled']);
            $table->dateTime('from_date');
            $table->dateTime('to_date')->nullable();
            $table->boolean('is_auto_reactivate')->default(false);
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();
            
            $table->index(['doctor_id', 'from_date', 'to_date'], 'idx_doctor_status_doctor_dates');
            $table->index(['is_auto_reactivate', 'to_date', 'processed_at'], 'idx_doctor_status_auto_reactivate');
            $table->index('processed_at', 'idx_doctor_status_processed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_status_history');
    }
};

