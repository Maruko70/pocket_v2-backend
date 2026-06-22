<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_complex', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->onDelete('cascade');
            $table->foreignId('complex_id')->constrained()->onDelete('cascade');
            $table->timestamps();
            
            $table->unique(['doctor_id', 'complex_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_complex');
    }
};

