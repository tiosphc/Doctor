<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->foreignId('received_by_user_id')->nullable()->after('processed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('processing_operation_key')->nullable()->unique();
            $table->char('processing_fingerprint', 64)->nullable();
        });
        Schema::table('sales_return_items', function (Blueprint $table): void {
            $table->string('non_restock_reason_code', 32)->nullable();
            $table->text('non_restock_note')->nullable();
        });
        DB::unprepared('DROP TRIGGER IF EXISTS completed_return_item_no_update');
        DB::unprepared("CREATE TRIGGER completed_return_item_no_update BEFORE UPDATE ON sales_return_items FOR EACH ROW BEGIN IF NOT EXISTS (SELECT 1 FROM sales_returns WHERE id = OLD.sales_return_id AND status = 'pending') OR NOT (OLD.sales_return_id <=> NEW.sales_return_id AND OLD.sales_order_item_id <=> NEW.sales_order_item_id AND OLD.quantity <=> NEW.quantity AND OLD.unit_value_snapshot <=> NEW.unit_value_snapshot AND OLD.return_value_snapshot <=> NEW.return_value_snapshot AND OLD.created_at <=> NEW.created_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only pending return disposition can be updated'; END IF; END");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS completed_return_item_no_update');
        DB::unprepared("CREATE TRIGGER completed_return_item_no_update BEFORE UPDATE ON sales_return_items FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Return items are immutable'; END");
        Schema::table('sales_return_items', fn (Blueprint $table) => $table->dropColumn(['non_restock_reason_code', 'non_restock_note']));
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->dropForeign(['received_by_user_id']);
            $table->dropColumn(['received_by_user_id', 'processing_operation_key', 'processing_fingerprint']);
        });
    }
};
