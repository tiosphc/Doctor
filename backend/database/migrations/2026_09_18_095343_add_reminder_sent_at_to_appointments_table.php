<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->timestamp('reminder_sent_at')->nullable()->after('status');
            $table->index(
                ['status', 'reminder_sent_at', 'appointment_date', 'start_time'],
                'appointments_reminder_eligibility_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropIndex('appointments_reminder_eligibility_index');
            $table->dropColumn('reminder_sent_at');
        });
    }
};
