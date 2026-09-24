<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::unprepared("CREATE TRIGGER sales_orders_code_immutable BEFORE UPDATE ON sales_orders FOR EACH ROW BEGIN IF OLD.order_code <> NEW.order_code AND NOT (OLD.order_code LIKE 'TMP%' AND NEW.order_code = CONCAT('ORD', LPAD(OLD.id, 8, '0'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sales Order code is immutable'; END IF; END");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sales_orders_code_immutable');
    }
};
