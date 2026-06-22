<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complex_major', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complex_id')->constrained()->onDelete('cascade');
            $table->foreignId('major_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['complex_id', 'major_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complex_major');
    }
};
