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
            $table->dropForeign(['sales_promotion_id']);
            $table->foreign('sales_promotion_id')->references('id')->on('sales_promotions')->nullOnDelete();
        });

        Schema::table('sales_promotion_redemptions', function (Blueprint $table): void {
            $table->dropForeign(['sales_promotion_id']);
            $table->unsignedBigInteger('sales_promotion_id')->nullable()->change();
            $table->foreign('sales_promotion_id')->references('id')->on('sales_promotions')->nullOnDelete();
        });

        DB::statement('ALTER TABLE sales_order_items DROP CHECK sales_order_item_amount_check');
        DB::statement('ALTER TABLE sales_order_items ADD CONSTRAINT sales_order_item_amount_check CHECK (quantity > 0 AND base_amount >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND line_total >= 0 AND ((is_gift = 0 AND unit_price_snapshot > 0) OR (is_gift = 1 AND unit_price_snapshot = 0 AND base_amount = 0 AND discount_amount = 0 AND tax_amount = 0 AND line_total = 0)))');
        Schema::table('sales_order_items', function (Blueprint $table): void {
            $table->dropForeign(['source_promotion_id']);
            $table->foreign('source_promotion_id')->references('id')->on('sales_promotions')->nullOnDelete();
        });
        DB::unprepared("CREATE TRIGGER sales_order_item_promotion_insert BEFORE INSERT ON sales_order_items FOR EACH ROW BEGIN
            IF (NEW.is_gift = 0 AND NEW.source_promotion_id IS NOT NULL)
                OR (NEW.is_gift = 1 AND NEW.source_promotion_id IS NULL)
            THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid gift promotion reference'; END IF;
        END");
        DB::unprepared("CREATE TRIGGER sales_order_item_promotion_update BEFORE UPDATE ON sales_order_items FOR EACH ROW BEGIN
            IF (NEW.is_gift = 0 AND NEW.source_promotion_id IS NOT NULL)
                OR (NEW.is_gift = 1 AND NEW.source_promotion_id IS NULL AND OLD.source_promotion_id IS NOT NULL)
            THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid gift promotion reference'; END IF;
        END");
    }

    public function down(): void
    {
        if (DB::table('sales_promotion_redemptions')->whereNull('sales_promotion_id')->exists()
            || DB::table('sales_order_items')->where('is_gift', true)->whereNull('source_promotion_id')->exists()) {
            throw new RuntimeException('Archived promotion references must be retained; this migration cannot be rolled back.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS sales_order_item_promotion_update');
        DB::unprepared('DROP TRIGGER IF EXISTS sales_order_item_promotion_insert');
        Schema::table('sales_order_items', function (Blueprint $table): void {
            $table->dropForeign(['source_promotion_id']);
            $table->foreign('source_promotion_id')->references('id')->on('sales_promotions')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE sales_order_items DROP CHECK sales_order_item_amount_check');
        DB::statement('ALTER TABLE sales_order_items ADD CONSTRAINT sales_order_item_amount_check CHECK (quantity > 0 AND base_amount >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND line_total >= 0 AND ((is_gift = 0 AND unit_price_snapshot > 0 AND source_promotion_id IS NULL) OR (is_gift = 1 AND unit_price_snapshot = 0 AND base_amount = 0 AND discount_amount = 0 AND tax_amount = 0 AND line_total = 0 AND source_promotion_id IS NOT NULL)))');

        Schema::table('sales_promotion_redemptions', function (Blueprint $table): void {
            $table->dropForeign(['sales_promotion_id']);
            $table->unsignedBigInteger('sales_promotion_id')->nullable(false)->change();
            $table->foreign('sales_promotion_id')->references('id')->on('sales_promotions')->restrictOnDelete();
        });
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropForeign(['sales_promotion_id']);
            $table->foreign('sales_promotion_id')->references('id')->on('sales_promotions')->restrictOnDelete();
        });
    }
};
