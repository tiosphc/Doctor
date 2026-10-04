<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_promotion_redemptions', function (Blueprint $table): void {
            $table->unique(['sales_order_id', 'sales_promotion_id'], 'promotion_order_campaign_unique');
            $table->dropUnique(['sales_order_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('sales_promotion_redemptions')->select('sales_order_id')
            ->groupBy('sales_order_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Orders using both a discount and a gift must be preserved.');
        }
        Schema::table('sales_promotion_redemptions', function (Blueprint $table): void {
            $table->unique('sales_order_id');
            $table->dropUnique('promotion_order_campaign_unique');
        });
    }
};
