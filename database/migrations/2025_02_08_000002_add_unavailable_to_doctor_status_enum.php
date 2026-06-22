<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE doctors MODIFY COLUMN status ENUM('active', 'unavailable', 'suspended', 'disabled') NOT NULL DEFAULT 'active'");
        DB::statement("ALTER TABLE doctor_status_history MODIFY COLUMN status ENUM('active', 'unavailable', 'suspended', 'disabled') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE doctors MODIFY COLUMN status ENUM('active', 'suspended', 'disabled') NOT NULL DEFAULT 'active'");
        DB::statement("ALTER TABLE doctor_status_history MODIFY COLUMN status ENUM('active', 'suspended', 'disabled') NOT NULL");
    }
};
