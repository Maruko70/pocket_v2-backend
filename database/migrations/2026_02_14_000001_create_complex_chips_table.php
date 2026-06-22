<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complex_chips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->constrained()->onDelete('cascade');
            $table->string('label', 255);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['complex_id']);
        });

        Schema::create('doctor_complex_chip', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->onDelete('cascade');
            $table->foreignId('complex_chip_id')->constrained('complex_chips')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['doctor_id', 'complex_chip_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_complex_chip');
        Schema::dropIfExists('complex_chips');
    }
};
