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
            $table->foreignId('dealer_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('dealer_code_snapshot', 50)->nullable();
            $table->string('dealer_name_snapshot')->nullable();
            $table->foreignId('effective_tier_id_snapshot')->nullable()->constrained('dealer_tiers')->restrictOnDelete();
            $table->string('effective_tier_code_snapshot', 50)->nullable();
            $table->string('effective_tier_name_snapshot')->nullable();
            $table->string('tier_source_snapshot', 24)->nullable();
            $table->foreignId('tier_override_id_snapshot')->nullable()->constrained('dealer_tier_overrides')->restrictOnDelete();
            $table->index(['dealer_account_id', 'order_status', 'created_at'], 'sales_order_dealer_state_idx');
            $table->index(['sales_channel', 'order_source', 'created_at'], 'sales_order_channel_source_idx');
        });
        Schema::table('sales_order_items', function (Blueprint $table): void {
            $table->decimal('minimum_quantity_snapshot', 18, 3)->nullable();
        });
        DB::statement("ALTER TABLE sales_orders ADD CONSTRAINT sales_order_dealer_context_check CHECK ((sales_channel = 'retail' AND dealer_account_id IS NULL AND pricing_context_snapshot = 'retail') OR (sales_channel = 'dealer' AND dealer_account_id IS NOT NULL AND pricing_context_snapshot = 'dealer' AND dealer_code_snapshot IS NOT NULL AND dealer_name_snapshot IS NOT NULL AND effective_tier_id_snapshot IS NOT NULL AND effective_tier_code_snapshot IS NOT NULL AND effective_tier_name_snapshot IS NOT NULL AND tier_source_snapshot IS NOT NULL))");
        DB::statement('ALTER TABLE sales_order_items ADD CONSTRAINT sales_order_item_moq_check CHECK (minimum_quantity_snapshot IS NULL OR minimum_quantity_snapshot > 0)');
    }

    public function down(): void
    {
        if (DB::table('sales_orders')->where('sales_channel', 'dealer')->exists()) {
            throw new RuntimeException('Dealer Sales Orders exist; rolling back their commercial snapshots is unsafe.');
        }
        DB::statement('ALTER TABLE sales_order_items DROP CHECK sales_order_item_moq_check');
        DB::statement('ALTER TABLE sales_orders DROP CHECK sales_order_dealer_context_check');
        Schema::table('sales_order_items', function (Blueprint $table): void {
            $table->dropColumn('minimum_quantity_snapshot');
        });
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropIndex('sales_order_dealer_state_idx');
            $table->dropIndex('sales_order_channel_source_idx');
            $table->dropForeign(['dealer_account_id']);
            $table->dropForeign(['effective_tier_id_snapshot']);
            $table->dropForeign(['tier_override_id_snapshot']);
            $table->dropColumn(['dealer_account_id', 'dealer_code_snapshot', 'dealer_name_snapshot',
                'effective_tier_id_snapshot', 'effective_tier_code_snapshot', 'effective_tier_name_snapshot',
                'tier_source_snapshot', 'tier_override_id_snapshot']);
        });
    }
};
