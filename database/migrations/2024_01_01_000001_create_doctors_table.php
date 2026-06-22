<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('bio')->nullable();
            $table->string('image')->nullable();
            $table->enum('status', ['active', 'suspended', 'disabled'])->default('active');
            $table->timestamps();
            
            $table->index('status');
            $table->index(['status', 'created_at'], 'idx_doctors_status_created');
            $table->index('name');
        });
    }

    public function down(): void
    {
        // Disable foreign key checks temporarily
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('doctors');
        Schema::enableForeignKeyConstraints();
    }
};

