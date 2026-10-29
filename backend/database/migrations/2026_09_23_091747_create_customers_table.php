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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_code', 16)->nullable()->unique();
            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->constrained()
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('name');
            $table->string('primary_email')->nullable();
            $table->string('normalized_email')->nullable()->index();
            $table->string('primary_phone', 30)->nullable();
            $table->string('normalized_phone', 16)->nullable()->index();
            $table->timestamp('verified_email_at')->nullable();
            $table->timestamp('verified_phone_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('source', 30);
            $table->foreignId('merged_into_customer_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index('merged_into_customer_id');
            $table->foreign('merged_into_customer_id')
                ->references('id')
                ->on('customers')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
