<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sales_order_items DROP CHECK sales_order_item_discount_bound_check');
        DB::statement('ALTER TABLE sales_order_items ADD CONSTRAINT sales_order_item_discount_bound_check CHECK (base_amount >= 0 AND discount_amount >= 0 AND discount_amount <= base_amount AND line_total >= 0 AND line_total = base_amount - discount_amount + tax_amount)');
        DB::statement('ALTER TABLE sales_return_items ADD CONSTRAINT sales_return_net_value_nonnegative_check CHECK (return_value_snapshot IS NULL OR return_value_snapshot >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales_return_items DROP CHECK sales_return_net_value_nonnegative_check');
        DB::statement('ALTER TABLE sales_order_items DROP CHECK sales_order_item_discount_bound_check');
        DB::statement('ALTER TABLE sales_order_items ADD CONSTRAINT sales_order_item_discount_bound_check CHECK (discount_amount <= base_amount AND line_total = base_amount - discount_amount + tax_amount)');
    }
};
