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
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('country')->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->string('timezone', 100)->nullable();
            $table->string('type', 50)->nullable();
            $table->string('status', 16)->default('active');
            $table->boolean('is_default_sales')->default(false);
            $table->boolean('is_default_clinic')->default(false);
            $table->unsignedTinyInteger('active_sales_default')->nullable()->storedAs("case when `status` = 'active' and `is_default_sales` = 1 then 1 else null end")->unique();
            $table->unsignedTinyInteger('active_clinic_default')->nullable()->storedAs("case when `status` = 'active' and `is_default_clinic` = 1 then 1 else null end")->unique();
            $table->timestamps();
            $table->index(['status', 'name']);
        });

        Schema::create('inventory_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('on_hand_quantity', 18, 3)->default(0);
            $table->decimal('reserved_quantity', 18, 3)->default(0);
            $table->timestamps();
            $table->unique(['warehouse_id', 'product_variant_id']);
            $table->index('product_variant_id');
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('movement_type', 32);
            $table->decimal('quantity', 18, 3);
            $table->decimal('before_on_hand_quantity', 18, 3);
            $table->decimal('after_on_hand_quantity', 18, 3);
            $table->string('reference_type', 50)->nullable();
            $table->string('reference_id', 100)->nullable();
            $table->string('operation_key', 120)->unique();
            $table->string('reason_code', 80)->nullable();
            $table->text('reason_detail')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('source', 32);
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->index(['warehouse_id', 'product_variant_id', 'occurred_at'], 'stock_movement_pair_time_idx');
            $table->index(['movement_type', 'occurred_at'], 'stock_movement_type_time_idx');
            $table->index(['reference_type', 'reference_id'], 'stock_movement_reference_idx');
        });

        DB::statement('ALTER TABLE inventory_balances ADD CONSTRAINT inventory_balance_quantities_check CHECK (on_hand_quantity >= 0 AND reserved_quantity >= 0 AND reserved_quantity <= on_hand_quantity)');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movement_arithmetic_check CHECK (after_on_hand_quantity = before_on_hand_quantity + quantity AND before_on_hand_quantity >= 0 AND after_on_hand_quantity >= 0)');
        DB::unprepared("CREATE TRIGGER stock_movements_no_update BEFORE UPDATE ON stock_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock movements are immutable'");
        DB::unprepared("CREATE TRIGGER stock_movements_no_delete BEFORE DELETE ON stock_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock movements are immutable'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('inventory_balances');
        Schema::dropIfExists('warehouses');
    }
};
