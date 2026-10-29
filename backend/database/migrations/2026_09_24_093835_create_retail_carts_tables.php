<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('context', 16)->default('retail');
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('active_retail_user_id')->nullable()
                ->storedAs("case when `context` = 'retail' and `status` = 'active' then `user_id` else null end")
                ->unique('carts_one_active_retail_user');
            $table->foreignId('converted_sales_order_id')->nullable()->unique()
                ->constrained('sales_orders')->restrictOnDelete();
            $table->string('checkout_operation_key', 36)->nullable()->unique();
            $table->char('checkout_payload_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'context', 'status', 'created_at'], 'carts_user_context_status_idx');
        });

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 3);
            $table->timestamps();
            $table->unique(['cart_id', 'product_variant_id']);
        });

        DB::statement("ALTER TABLE carts ADD CONSTRAINT carts_lifecycle_check CHECK (context = 'retail' AND status IN ('active', 'converted') AND ((status = 'active' AND converted_sales_order_id IS NULL AND checkout_operation_key IS NULL AND checkout_payload_fingerprint IS NULL) OR (status = 'converted' AND converted_sales_order_id IS NOT NULL AND checkout_operation_key IS NOT NULL AND checkout_payload_fingerprint IS NOT NULL)))");
        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_quantity_check CHECK (quantity > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
