<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("CREATE TRIGGER sales_promotion_redemption_no_delete BEFORE DELETE ON sales_promotion_redemptions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Promotion redemptions are immutable'; END");
        DB::unprepared("CREATE TRIGGER sales_promotion_redemption_guard_update BEFORE UPDATE ON sales_promotion_redemptions FOR EACH ROW BEGIN
            IF NOT (OLD.sales_promotion_id <=> NEW.sales_promotion_id)
                OR NOT (OLD.sales_order_id <=> NEW.sales_order_id)
                OR NOT (OLD.sales_channel <=> NEW.sales_channel)
                OR NOT (OLD.buyer_user_id <=> NEW.buyer_user_id)
                OR NOT (OLD.dealer_account_id <=> NEW.dealer_account_id)
                OR NOT (OLD.promotion_code_snapshot <=> NEW.promotion_code_snapshot)
                OR NOT (OLD.discount_type_snapshot <=> NEW.discount_type_snapshot)
                OR NOT (OLD.discount_value_snapshot <=> NEW.discount_value_snapshot)
                OR NOT (OLD.discount_amount <=> NEW.discount_amount)
                OR NOT (OLD.redeemed_at <=> NEW.redeemed_at)
                OR NOT (OLD.created_at <=> NEW.created_at)
                OR OLD.status <> 'redeemed' OR NEW.status <> 'released'
                OR OLD.released_at IS NOT NULL OR NEW.released_at IS NULL
            THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Promotion redemption facts are immutable'; END IF;
        END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sales_promotion_redemption_guard_update');
        DB::unprepared('DROP TRIGGER IF EXISTS sales_promotion_redemption_no_delete');
    }
};
