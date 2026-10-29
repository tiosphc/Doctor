<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80);
            $table->string('normalized_code', 80)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('sales_scope', 16)->default('retail');
            $table->string('discount_type', 20);
            $table->decimal('discount_value', 18, 2);
            $table->decimal('max_discount_amount', 18, 2)->nullable();
            $table->decimal('minimum_order_amount', 18, 2)->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('total_usage_limit')->nullable();
            $table->unsignedInteger('per_buyer_usage_limit')->nullable();
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'starts_at', 'ends_at']);
        });
        DB::statement("ALTER TABLE sales_vouchers ADD CONSTRAINT sales_voucher_rules_check CHECK (discount_type IN ('percentage', 'fixed_amount') AND discount_value > 0 AND (discount_type <> 'percentage' OR discount_value <= 100) AND (max_discount_amount IS NULL OR max_discount_amount > 0) AND minimum_order_amount >= 0 AND sales_scope IN ('retail', 'dealer', 'both') AND status IN ('active', 'inactive') AND (starts_at IS NULL OR ends_at IS NULL OR starts_at < ends_at) AND (total_usage_limit IS NULL OR total_usage_limit > 0) AND (per_buyer_usage_limit IS NULL OR per_buyer_usage_limit > 0))");
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->foreignId('sales_voucher_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('sales_voucher_code_snapshot', 80)->nullable();
            $table->decimal('sales_voucher_discount_snapshot', 18, 2)->nullable();
        });
        Schema::create('sales_voucher_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_voucher_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('buyer_user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('discount_amount', 18, 2);
            $table->string('status', 16)->default('redeemed');
            $table->dateTime('redeemed_at');
            $table->dateTime('released_at')->nullable();
            $table->timestamps();
            $table->index(['sales_voucher_id', 'status']);
            $table->index(['sales_voucher_id', 'buyer_user_id', 'status'], 'sales_voucher_buyer_usage_idx');
        });
        DB::statement("ALTER TABLE sales_voucher_redemptions ADD CONSTRAINT sales_voucher_redemption_check CHECK (discount_amount > 0 AND status IN ('redeemed', 'released'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_voucher_redemptions');
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sales_voucher_id');
            $table->dropColumn(['sales_voucher_code_snapshot', 'sales_voucher_discount_snapshot']);
        });
        Schema::dropIfExists('sales_vouchers');
    }
};
