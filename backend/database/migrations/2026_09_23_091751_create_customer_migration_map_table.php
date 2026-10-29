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
        Schema::create('customer_migration_map', function (Blueprint $table) {
            $table->id();
            $table->string('batch_key');
            $table->string('normalization_version', 30);
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('decision', 30);
            $table->foreignId('conflict_id')
                ->nullable()
                ->constrained('customer_identity_conflicts')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->char('input_fingerprint', 64);
            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
            $table->index('batch_key');
            $table->index('customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_migration_map');
    }
};
