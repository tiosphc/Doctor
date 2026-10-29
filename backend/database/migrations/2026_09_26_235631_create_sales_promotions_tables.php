<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_promotions', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80);
            $table->string('normalized_code', 80)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('discount_type', 20);
            $table->decimal('discount_value', 18, 2);
            $table->decimal('max_discount_amount', 18, 2)->nullable();
            $table->decimal('minimum_order_amount', 18, 2)->default(0);
            $table->string('sales_scope', 16);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('total_usage_limit')->nullable();
            $table->unsignedInteger('per_buyer_usage_limit')->nullable();
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'starts_at', 'ends_at']);
        });
        DB::statement("ALTER TABLE sales_promotions ADD CONSTRAINT sales_promotion_rules_check CHECK (discount_type IN ('percentage', 'fixed_amount') AND discount_value > 0 AND (discount_type <> 'percentage' OR discount_value <= 100) AND (max_discount_amount IS NULL OR max_discount_amount > 0) AND minimum_order_amount >= 0 AND sales_scope IN ('retail', 'dealer', 'both') AND status IN ('active', 'inactive') AND (starts_at IS NULL OR ends_at IS NULL OR starts_at < ends_at) AND (total_usage_limit IS NULL OR total_usage_limit > 0) AND (per_buyer_usage_limit IS NULL OR per_buyer_usage_limit > 0))");

        Schema::create('sales_promotion_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_promotion_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['sales_promotion_id', 'product_id']);
            $table->unique(['sales_promotion_id', 'product_category_id'], 'promotion_category_unique');
        });
        DB::statement('ALTER TABLE sales_promotion_targets ADD CONSTRAINT sales_promotion_target_one_check CHECK ((product_id IS NULL) <> (product_category_id IS NULL))');

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->foreignId('sales_promotion_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('promotion_code_snapshot', 80)->nullable();
            $table->string('promotion_name_snapshot')->nullable();
            $table->string('promotion_discount_type_snapshot', 20)->nullable();
            $table->decimal('promotion_discount_value_snapshot', 18, 2)->nullable();
        });

        Schema::create('sales_promotion_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_promotion_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('sales_channel', 16);
            $table->foreignId('buyer_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('dealer_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('promotion_code_snapshot', 80);
            $table->string('discount_type_snapshot', 20);
            $table->decimal('discount_value_snapshot', 18, 2);
            $table->decimal('discount_amount', 18, 2);
            $table->string('status', 16)->default('redeemed');
            $table->dateTime('redeemed_at');
            $table->dateTime('released_at')->nullable();
            $table->timestamps();
            $table->index(['sales_promotion_id', 'status']);
            $table->index(['sales_promotion_id', 'buyer_user_id', 'status'], 'promotion_buyer_usage_idx');
            $table->index(['sales_promotion_id', 'dealer_account_id', 'status'], 'promotion_dealer_usage_idx');
        });
        DB::statement("ALTER TABLE sales_promotion_redemptions ADD CONSTRAINT promotion_redemption_check CHECK (discount_amount >= 0 AND ((sales_channel = 'retail' AND buyer_user_id IS NOT NULL AND dealer_account_id IS NULL) OR (sales_channel = 'dealer' AND dealer_account_id IS NOT NULL)) AND status IN ('redeemed', 'released'))");
        DB::statement('ALTER TABLE sales_order_items ADD CONSTRAINT sales_order_item_discount_bound_check CHECK (discount_amount <= base_amount AND line_total = base_amount - discount_amount + tax_amount)');
        Schema::table('sales_return_items', function (Blueprint $table): void {
            $table->decimal('return_value_snapshot', 18, 2)->nullable();
        });
        Schema::table('dealer_order_import_rows', function (Blueprint $table): void {
            $table->string('promotion_code', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dealer_order_import_rows', fn (Blueprint $table) => $table->dropColumn('promotion_code'));
        Schema::table('sales_return_items', fn (Blueprint $table) => $table->dropColumn('return_value_snapshot'));
        DB::statement('ALTER TABLE sales_order_items DROP CHECK sales_order_item_discount_bound_check');
        Schema::dropIfExists('sales_promotion_redemptions');
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sales_promotion_id');
            $table->dropColumn(['promotion_code_snapshot', 'promotion_name_snapshot', 'promotion_discount_type_snapshot', 'promotion_discount_value_snapshot']);
        });
        Schema::dropIfExists('sales_promotion_targets');
        Schema::dropIfExists('sales_promotions');
    }
};
