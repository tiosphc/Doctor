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
        Schema::create('customer_identity_conflicts', function (Blueprint $table) {
            $table->id();
            $table->char('conflict_key', 64)->unique();
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id');
            $table->string('reason_code', 50);
            $table->string('status', 20)->default('pending');
            $table->string('resolution', 40)->nullable();
            $table->foreignId('resolution_customer_id')
                ->nullable()
                ->constrained('customers')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('resolved_by')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at', 'id']);
            $table->index(['source_type', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_identity_conflicts');
    }
};
