<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('created_by_admin_user_id')
                ->nullable()
                ->constrained('admin_users')
                ->nullOnDelete()
                ->after('status');
            $table->string('created_source')->nullable()->after('created_by_admin_user_id');

            $table->index(['created_source', 'created_by_admin_user_id'], 'idx_reservations_created_source_admin');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex('idx_reservations_created_source_admin');
            $table->dropConstrainedForeignId('created_by_admin_user_id');
            $table->dropColumn('created_source');
        });
    }
};

