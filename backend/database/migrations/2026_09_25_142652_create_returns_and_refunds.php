<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->string('refund_status', 24)->default('none')->after('payment_status');
        });
        Schema::create('sales_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('return_code', 32)->unique();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('status', 16);
            $table->string('reason', 1000);
            $table->text('note')->nullable();
            $table->foreignId('processed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_key')->unique();
            $table->char('request_fingerprint', 64);
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->index(['sales_order_id', 'status']);
        });
        Schema::create('sales_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 3);
            $table->decimal('restock_quantity', 18, 3);
            $table->decimal('non_restock_quantity', 18, 3);
            $table->decimal('unit_value_snapshot', 18, 2);
            $table->timestamps();
            $table->unique(['sales_return_id', 'sales_order_item_id']);
        });
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->string('refund_code', 32)->unique();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_return_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('currency', 3);
            $table->decimal('amount', 18, 2);
            $table->string('refund_method', 32);
            $table->string('status', 16);
            $table->string('reason_code', 32);
            $table->text('note')->nullable();
            $table->string('external_reference', 255)->nullable();
            $table->string('external_reference_normalized', 255)->nullable();
            $table->foreignId('processed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_key')->unique();
            $table->char('request_fingerprint', 64);
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['refund_method', 'external_reference_normalized'], 'refund_method_reference_unique');
            $table->index(['sales_order_id', 'status']);
        });
        Schema::create('refund_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_allocation_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();
            $table->unique(['refund_id', 'payment_allocation_id']);
        });
        DB::statement('ALTER TABLE sales_return_items ADD CONSTRAINT return_quantities_valid CHECK (quantity > 0 AND restock_quantity >= 0 AND non_restock_quantity >= 0 AND restock_quantity + non_restock_quantity = quantity)');
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refund_amount_positive CHECK (amount > 0)');
        DB::statement('ALTER TABLE refund_allocations ADD CONSTRAINT refund_allocation_positive CHECK (amount > 0)');
        DB::unprepared("CREATE TRIGGER completed_refund_no_update BEFORE UPDATE ON refunds FOR EACH ROW BEGIN IF OLD.status = 'completed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Completed refunds are immutable'; END IF; END");
        DB::unprepared("CREATE TRIGGER completed_refund_no_delete BEFORE DELETE ON refunds FOR EACH ROW BEGIN IF OLD.status = 'completed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Completed refunds are immutable'; END IF; END");
        DB::unprepared("CREATE TRIGGER completed_refund_allocation_no_insert BEFORE INSERT ON refund_allocations FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM refunds WHERE id = NEW.refund_id AND status = 'completed') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Completed refund allocations are immutable'; END IF; END");
        DB::unprepared("CREATE TRIGGER completed_refund_allocation_no_update BEFORE UPDATE ON refund_allocations FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund allocations are immutable'; END");
        DB::unprepared("CREATE TRIGGER completed_refund_allocation_no_delete BEFORE DELETE ON refund_allocations FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refund allocations are immutable'; END");
        DB::unprepared("CREATE TRIGGER completed_return_no_update BEFORE UPDATE ON sales_returns FOR EACH ROW BEGIN IF OLD.status = 'completed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Completed returns are immutable'; END IF; END");
        DB::unprepared("CREATE TRIGGER completed_return_no_delete BEFORE DELETE ON sales_returns FOR EACH ROW BEGIN IF OLD.status = 'completed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Completed returns are immutable'; END IF; END");
        DB::unprepared("CREATE TRIGGER completed_return_item_no_insert BEFORE INSERT ON sales_return_items FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM sales_returns WHERE id = NEW.sales_return_id AND status = 'completed') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Completed return items are immutable'; END IF; END");
        DB::unprepared("CREATE TRIGGER completed_return_item_no_update BEFORE UPDATE ON sales_return_items FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Return items are immutable'; END");
        DB::unprepared("CREATE TRIGGER completed_return_item_no_delete BEFORE DELETE ON sales_return_items FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Return items are immutable'; END");
    }

    public function down(): void
    {
        foreach (['completed_return_item_no_delete', 'completed_return_item_no_update', 'completed_return_item_no_insert', 'completed_return_no_delete', 'completed_return_no_update', 'completed_refund_allocation_no_delete', 'completed_refund_allocation_no_update', 'completed_refund_allocation_no_insert', 'completed_refund_no_delete', 'completed_refund_no_update'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::dropIfExists('refund_allocations');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');
        Schema::table('sales_orders', fn (Blueprint $table) => $table->dropColumn('refund_status'));
    }
};
