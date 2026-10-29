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
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->date('expected_delivery_date')->nullable();
            $table->string('supplier_order_reference', 100)->nullable();
            $table->string('payment_status', 24)->default('unpaid');
            $table->decimal('paid_amount', 20, 2)->default(0);
        });

        Schema::create('purchase_order_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_key')->unique();
            $table->decimal('amount', 20, 2);
            $table->string('payment_method', 50);
            $table->timestamp('paid_at');
            $table->string('external_reference', 100)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['purchase_order_id', 'paid_at']);
        });

        Schema::table('inventory_balances', function (Blueprint $table): void {
            $table->decimal('average_unit_cost', 20, 6)->nullable();
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->decimal('unit_cost', 20, 6)->nullable();
            $table->decimal('cost_amount', 20, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropColumn(['unit_cost', 'cost_amount']);
        });
        Schema::table('inventory_balances', function (Blueprint $table): void {
            $table->dropColumn('average_unit_cost');
        });
        Schema::dropIfExists('purchase_order_payments');
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn(['expected_delivery_date', 'supplier_order_reference', 'payment_status', 'paid_amount']);
        });
    }
};
