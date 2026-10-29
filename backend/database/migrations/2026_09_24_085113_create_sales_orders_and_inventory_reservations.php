<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_code', 24)->unique();
            $table->string('creation_operation_key', 36)->unique();
            $table->char('creation_fingerprint', 64);
            $table->string('sales_channel', 16);
            $table->string('order_source', 24);
            $table->foreignId('buyer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('currency', 3);
            $table->string('recipient_name');
            $table->string('recipient_phone', 50);
            $table->string('recipient_email')->nullable();
            $table->string('shipping_address_line1');
            $table->string('shipping_address_line2')->nullable();
            $table->string('shipping_city');
            $table->string('shipping_province');
            $table->string('shipping_country');
            $table->string('shipping_postal_code', 30)->nullable();
            $table->text('delivery_note')->nullable();
            $table->string('order_status', 24)->default('draft');
            $table->string('payment_status', 24)->default('unpaid');
            $table->string('fulfillment_status', 24)->default('unfulfilled');
            $table->string('pricing_context_snapshot', 16)->default('retail');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('shipping_total', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->char('price_resolution_fingerprint', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_reason', 1000)->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->index(['buyer_user_id', 'order_status', 'created_at']);
            $table->index(['warehouse_id', 'order_status', 'created_at']);
            $table->index(['order_status', 'payment_status', 'fulfillment_status', 'created_at'], 'sales_order_state_idx');
        });
        Schema::create('sales_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('product_code_snapshot', 50);
            $table->string('product_name_snapshot');
            $table->string('sku_snapshot', 100);
            $table->string('variant_name_snapshot');
            $table->string('unit_code_snapshot', 50);
            $table->string('unit_name_snapshot');
            $table->decimal('quantity', 18, 3);
            $table->string('pricing_context_snapshot', 16);
            $table->foreignId('price_list_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('price_list_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('price_resolution_fingerprint', 64);
            $table->decimal('unit_price_snapshot', 18, 2);
            $table->decimal('base_amount', 18, 2);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2);
            $table->timestamps();
            $table->unique(['sales_order_id', 'product_variant_id']);
        });
        Schema::create('inventory_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('original_quantity', 18, 3);
            $table->decimal('consumed_quantity', 18, 3)->default(0);
            $table->decimal('released_quantity', 18, 3)->default(0);
            $table->string('status', 24)->default('active');
            $table->dateTime('expires_at')->nullable();
            $table->string('operation_key', 36)->unique();
            $table->timestamps();
            $table->unique('sales_order_item_id');
            $table->index(['warehouse_id', 'product_variant_id', 'status'], 'reservation_pair_status_idx');
        });
        Schema::create('sales_order_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->string('operation_key', 36)->unique();
            $table->string('operation_type', 24);
            $table->char('payload_fingerprint', 64);
            $table->timestamps();
            $table->index(['sales_order_id', 'operation_type']);
        });
        DB::statement('ALTER TABLE sales_orders ADD CONSTRAINT sales_order_totals_check CHECK (subtotal >= 0 AND discount_total >= 0 AND tax_total >= 0 AND shipping_total >= 0 AND grand_total >= 0 AND grand_total = subtotal - discount_total + tax_total + shipping_total)');
        DB::statement("ALTER TABLE sales_orders ADD CONSTRAINT sales_order_channel_check CHECK (sales_channel IN ('retail', 'dealer') AND (sales_channel <> 'retail' OR buyer_user_id IS NOT NULL))");
        DB::statement('ALTER TABLE sales_order_items ADD CONSTRAINT sales_order_item_amount_check CHECK (quantity > 0 AND unit_price_snapshot > 0 AND base_amount >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND line_total >= 0)');
        DB::statement('ALTER TABLE inventory_reservations ADD CONSTRAINT inventory_reservation_quantities_check CHECK (original_quantity > 0 AND consumed_quantity >= 0 AND released_quantity >= 0 AND consumed_quantity + released_quantity <= original_quantity)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_operations');
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_orders');
    }
};
