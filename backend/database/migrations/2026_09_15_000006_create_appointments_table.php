<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('doctor_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('service_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->date('appointment_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('status', 20)->default('pending');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(
                ['doctor_id', 'appointment_date', 'status', 'start_time', 'end_time'],
                'appointments_doctor_availability_index'
            );
            $table->index(['user_id', 'appointment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
