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
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->unique()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->string('status', 20)->default('published');
            $table->timestamps();
            $table->index(['doctor_id', 'status', 'created_at']);
            $table->index(['service_id', 'status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('value', 8, 2);
            $table->string('source', 30);
            $table->unsignedBigInteger('source_id');
            $table->string('status', 20)->default('active');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->unique(['source', 'source_id']);
            $table->index(['user_id', 'status', 'expires_at']);
            $table->index('expires_at');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('voucher_id')->nullable()->after('service_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->decimal('original_price', 12, 2)->nullable()->after('voucher_id');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('original_price');
            $table->decimal('final_price', 12, 2)->nullable()->after('discount_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voucher_id');
            $table->dropColumn(['original_price', 'discount_amount', 'final_price']);
        });
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('reviews');
    }
};
