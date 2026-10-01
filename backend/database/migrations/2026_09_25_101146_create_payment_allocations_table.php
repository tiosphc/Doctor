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
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->decimal('allocated_amount', 18, 2);
            $table->timestamps();
            $table->unique(['payment_id', 'sales_order_id']);
            $table->index(['sales_order_id', 'payment_id']);
        });
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocation_positive CHECK (allocated_amount > 0)');
        DB::unprepared("CREATE TRIGGER settled_allocation_no_update BEFORE UPDATE ON payment_allocations FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM payments WHERE id = OLD.payment_id AND status = 'settled') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Settled allocations are immutable'; END IF; END");
        DB::unprepared("CREATE TRIGGER settled_allocation_no_delete BEFORE DELETE ON payment_allocations FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM payments WHERE id = OLD.payment_id AND status = 'settled') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Settled allocations are immutable'; END IF; END");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS settled_allocation_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS settled_allocation_no_update');
        Schema::dropIfExists('payment_allocations');
    }
};
