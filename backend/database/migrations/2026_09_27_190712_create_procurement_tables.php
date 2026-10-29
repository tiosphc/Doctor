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
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('tax_code', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->index(['status', 'name']);
        });
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->string('currency', 3)->default('VND');
            $table->decimal('total_amount', 20, 2);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['supplier_id', 'status', 'id']);
        });
        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('sku_snapshot');
            $table->string('name_snapshot');
            $table->decimal('ordered_quantity', 18, 3);
            $table->decimal('received_quantity', 18, 3)->default(0);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('line_total', 20, 2);
            $table->timestamps();
            $table->unique(['purchase_order_id', 'product_variant_id'], 'po_variant_unique');
        });
        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_key')->unique();
            $table->char('payload_hash', 64);
            $table->string('supplier_reference', 100)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at');
            $table->timestamps();
            $table->index(['purchase_order_id', 'id']);
        });
        Schema::create('goods_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 3);
            $table->decimal('returned_quantity', 18, 3)->default(0);
            $table->foreignId('stock_movement_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['goods_receipt_id', 'purchase_order_item_id'], 'receipt_po_item_unique');
        });
        Schema::create('purchase_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_key')->unique();
            $table->char('payload_hash', 64);
            $table->text('reason');
            $table->foreignId('returned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('returned_at');
            $table->timestamps();
            $table->index(['purchase_order_id', 'id']);
        });
        Schema::create('purchase_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('goods_receipt_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 3);
            $table->foreignId('stock_movement_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['purchase_return_id', 'goods_receipt_item_id'], 'return_receipt_item_unique');
        });

        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT po_item_quantities_check CHECK (ordered_quantity > 0 AND received_quantity >= 0 AND received_quantity <= ordered_quantity AND unit_price >= 0 AND line_total >= 0)');
        DB::statement('ALTER TABLE goods_receipt_items ADD CONSTRAINT receipt_item_quantities_check CHECK (quantity > 0 AND returned_quantity >= 0 AND returned_quantity <= quantity)');
        DB::statement('ALTER TABLE purchase_return_items ADD CONSTRAINT purchase_return_quantity_check CHECK (quantity > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
