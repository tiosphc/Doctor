<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_service', function (Blueprint $table) {
            $table->foreignId('doctor_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('service_id')
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->primary(['doctor_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_service');
    }
};
