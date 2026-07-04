<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('target_type'); // all | user | doctor
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_name')->nullable();
            $table->string('title');
            $table->text('body');
            $table->string('image_url', 2048)->nullable();
            $table->unsignedInteger('tokens_targeted')->default(0);
            $table->unsignedInteger('devices_delivered')->default(0);
            $table->unsignedInteger('devices_failed')->default(0);
            $table->string('status')->default('sent'); // sent | partial | failed
            $table->text('failure_reason')->nullable();
            $table->foreignId('resent_from_id')->nullable()->constrained('notification_broadcasts')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['target_type', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_broadcasts');
    }
};
