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
        Schema::table('payments', function (Blueprint $table): void {
            $table->index(['status', 'settled_at'], 'report_payment_settlement_idx');
        });
        Schema::table('refunds', function (Blueprint $table): void {
            $table->index(['status', 'completed_at'], 'report_refund_completion_idx');
        });
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'report_order_creation_idx');
        });
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->index('received_at', 'report_receipt_date_idx');
        });
        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->index('returned_at', 'report_purchase_return_date_idx');
        });
        Schema::table('sales_promotion_redemptions', function (Blueprint $table): void {
            $table->index('redeemed_at', 'report_promotion_redeemed_idx');
            $table->index(['status', 'released_at'], 'report_promotion_released_idx');
        });
        Schema::table('dealer_wallet_top_up_requests', function (Blueprint $table): void {
            $table->index(['status', 'paid_at'], 'report_topup_paid_idx');
        });
        Schema::table('dealer_wallet_transactions', function (Blueprint $table): void {
            $table->index('created_at', 'report_wallet_flow_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dealer_wallet_transactions', function (Blueprint $table): void {
            $table->dropIndex('report_wallet_flow_date_idx');
        });
        Schema::table('dealer_wallet_top_up_requests', function (Blueprint $table): void {
            $table->dropIndex('report_topup_paid_idx');
        });
        Schema::table('sales_promotion_redemptions', function (Blueprint $table): void {
            $table->dropIndex('report_promotion_redeemed_idx');
            $table->dropIndex('report_promotion_released_idx');
        });
        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->dropIndex('report_purchase_return_date_idx');
        });
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->dropIndex('report_receipt_date_idx');
        });
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropIndex('report_order_creation_idx');
        });
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropIndex('report_refund_completion_idx');
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('report_payment_settlement_idx');
        });
    }
};
