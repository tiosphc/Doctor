<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->date('original_appointment_date')->nullable()->after('end_time');
            $table->time('original_start_time')->nullable()->after('original_appointment_date');
            $table->time('original_end_time')->nullable()->after('original_start_time');
            $table->timestamp('rescheduled_at')->nullable()->after('original_end_time');
            $table->unsignedInteger('reschedule_count')->default(0)->after('rescheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn([
                'original_appointment_date',
                'original_start_time',
                'original_end_time',
                'rescheduled_at',
                'reschedule_count',
            ]);
        });
    }
};
