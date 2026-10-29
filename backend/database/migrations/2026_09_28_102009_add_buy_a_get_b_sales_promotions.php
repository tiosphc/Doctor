<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('can_be_gift')->default(false);
            $table->boolean('gift_only')->default(false);
            $table->index(['status', 'gift_only', 'can_be_gift']);
        });
        DB::statement('ALTER TABLE products ADD CONSTRAINT product_gift_flags_check CHECK (gift_only = 0 OR can_be_gift = 1)');

        DB::statement('ALTER TABLE sales_promotions DROP CHECK sales_promotion_rules_check');
        DB::statement("ALTER TABLE sales_promotions ADD CONSTRAINT sales_promotion_rules_check CHECK (discount_type IN ('percentage', 'fixed_amount', 'buy_a_get_b') AND ((discount_type = 'buy_a_get_b' AND discount_value = 0 AND max_discount_amount IS NULL) OR (discount_type <> 'buy_a_get_b' AND discount_value > 0 AND (discount_type <> 'percentage' OR discount_value <= 100))) AND (max_discount_amount IS NULL OR max_discount_amount > 0) AND minimum_order_amount >= 0 AND sales_scope IN ('retail', 'dealer', 'both') AND status IN ('active', 'inactive') AND (starts_at IS NULL OR ends_at IS NULL OR starts_at < ends_at) AND (total_usage_limit IS NULL OR total_usage_limit > 0) AND (per_buyer_usage_limit IS NULL OR per_buyer_usage_limit > 0))");

        Schema::create('sales_promotion_gift_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_promotion_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('buy_product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('buy_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->decimal('minimum_buy_quantity', 18, 3);
            $table->foreignId('gift_product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('gift_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('gift_quantity', 18, 3);
            $table->boolean('repeat_per_multiple')->default(false);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE sales_promotion_gift_rules ADD CONSTRAINT sales_promotion_gift_quantities_check CHECK (minimum_buy_quantity > 0 AND gift_quantity > 0)');

        Schema::create('sales_promotion_dealer_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_promotion_id')->constrained()->restrictOnDelete();
            $table->foreignId('dealer_tier_id')->constrained()->restrictOnDelete();
            $table->unique(['sales_promotion_id', 'dealer_tier_id'], 'promotion_dealer_tier_unique');
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->json('promotion_gift_snapshot')->nullable();
        });
        Schema::table('sales_order_items', fn (Blueprint $table) => $table->index('sales_order_id', 'sales_order_item_order_idx'));
        Schema::table('sales_order_items', function (Blueprint $table): void {
            $table->dropUnique(['sales_order_id', 'product_variant_id']);
            $table->boolean('is_gift')->default(false);
            $table->foreignId('source_promotion_id')->nullable()->constrained('sales_promotions')->restrictOnDelete();
            $table->unique(['sales_order_id', 'product_variant_id', 'is_gift'], 'sales_order_item_variant_kind_unique');
        });
        DB::statement('ALTER TABLE sales_order_items DROP CHECK sales_order_item_amount_check');
        DB::statement('ALTER TABLE sales_order_items ADD CONSTRAINT sales_order_item_amount_check CHECK (quantity > 0 AND base_amount >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND line_total >= 0 AND ((is_gift = 0 AND unit_price_snapshot > 0 AND source_promotion_id IS NULL) OR (is_gift = 1 AND unit_price_snapshot = 0 AND base_amount = 0 AND discount_amount = 0 AND tax_amount = 0 AND line_total = 0 AND source_promotion_id IS NOT NULL)))');
    }

    public function down(): void
    {
        if (DB::table('sales_order_items')->where('is_gift', true)->exists()
            || DB::table('sales_promotions')->where('discount_type', 'buy_a_get_b')->exists()) {
            throw new RuntimeException('Resolve historical Gift Orders and promotions before rollback.');
        }
        DB::statement('ALTER TABLE sales_order_items DROP CHECK sales_order_item_amount_check');
        Schema::table('sales_order_items', function (Blueprint $table): void {
            $table->dropUnique('sales_order_item_variant_kind_unique');
            $table->dropConstrainedForeignId('source_promotion_id');
            $table->dropColumn('is_gift');
            $table->unique(['sales_order_id', 'product_variant_id']);
        });
        Schema::table('sales_order_items', fn (Blueprint $table) => $table->dropIndex('sales_order_item_order_idx'));
        DB::statement('ALTER TABLE sales_order_items ADD CONSTRAINT sales_order_item_amount_check CHECK (quantity > 0 AND unit_price_snapshot > 0 AND base_amount >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND line_total >= 0)');
        Schema::table('sales_orders', fn (Blueprint $table) => $table->dropColumn('promotion_gift_snapshot'));
        Schema::dropIfExists('sales_promotion_dealer_tiers');
        Schema::dropIfExists('sales_promotion_gift_rules');
        DB::statement('ALTER TABLE sales_promotions DROP CHECK sales_promotion_rules_check');
        DB::statement("ALTER TABLE sales_promotions ADD CONSTRAINT sales_promotion_rules_check CHECK (discount_type IN ('percentage', 'fixed_amount') AND discount_value > 0 AND (discount_type <> 'percentage' OR discount_value <= 100) AND (max_discount_amount IS NULL OR max_discount_amount > 0) AND minimum_order_amount >= 0 AND sales_scope IN ('retail', 'dealer', 'both') AND status IN ('active', 'inactive') AND (starts_at IS NULL OR ends_at IS NULL OR starts_at < ends_at) AND (total_usage_limit IS NULL OR total_usage_limit > 0) AND (per_buyer_usage_limit IS NULL OR per_buyer_usage_limit > 0))");
        DB::statement('ALTER TABLE products DROP CHECK product_gift_flags_check');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['status', 'gift_only', 'can_be_gift']);
            $table->dropColumn(['can_be_gift', 'gift_only']);
        });
    }
};
