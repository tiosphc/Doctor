<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week')
                ->comment('ISO-8601 weekday: 1=Monday, ..., 7=Sunday');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['doctor_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_schedules');
    }
};
