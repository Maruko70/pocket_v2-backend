<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedInteger('ticket_number')->nullable()->after('time');
            $table->time('time')->nullable()->change();
            $table->unique(['date', 'doctor_id', 'complex_id', 'ticket_number'], 'reservations_ticket_unique');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique('reservations_ticket_unique');
            $table->dropColumn('ticket_number');
            $table->time('time')->nullable(false)->change();
        });
    }
};
