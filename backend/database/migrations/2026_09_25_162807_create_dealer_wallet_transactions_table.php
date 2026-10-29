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
        Schema::create('dealer_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_wallet_id')->constrained()->restrictOnDelete();
            $table->string('transaction_code')->unique();
            $table->enum('direction', ['credit', 'debit']);
            $table->enum('type', ['deposit_credit', 'order_debit', 'refund_credit']);
            $table->decimal('amount', 19, 2);
            $table->char('currency', 3);
            $table->decimal('balance_before', 19, 2);
            $table->decimal('balance_after', 19, 2);
            // The deposit table is created by the following migration; attach this FK there.
            $table->unsignedBigInteger('dealer_wallet_deposit_id')->nullable();
            $table->foreignId('sales_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('operation_key')->nullable();
            $table->timestamps();
            $table->unique(['dealer_wallet_id', 'operation_key']);
            $table->unique('sales_order_id');
            $table->unique('refund_id');
        });
        DB::statement('ALTER TABLE dealer_wallet_transactions ADD CONSTRAINT dealer_wallet_transactions_amount_positive CHECK (amount > 0)');
        DB::statement('ALTER TABLE dealer_wallet_transactions ADD CONSTRAINT dealer_wallet_transactions_balance_nonnegative CHECK (balance_before >= 0 AND balance_after >= 0)');
        DB::statement("ALTER TABLE dealer_wallet_transactions ADD CONSTRAINT dealer_wallet_transactions_direction_type_check CHECK ((direction = 'credit' AND type IN ('deposit_credit', 'refund_credit')) OR (direction = 'debit' AND type = 'order_debit'))");
        DB::statement("ALTER TABLE dealer_wallet_transactions ADD CONSTRAINT dealer_wallet_transactions_link_check CHECK ((type = 'deposit_credit' AND dealer_wallet_deposit_id IS NOT NULL AND sales_order_id IS NULL AND payment_id IS NULL AND refund_id IS NULL) OR (type = 'order_debit' AND dealer_wallet_deposit_id IS NULL AND sales_order_id IS NOT NULL AND payment_id IS NOT NULL AND refund_id IS NULL) OR (type = 'refund_credit' AND dealer_wallet_deposit_id IS NULL AND sales_order_id IS NULL AND payment_id IS NULL AND refund_id IS NOT NULL))");
        DB::unprepared("CREATE TRIGGER dealer_wallet_transactions_no_update BEFORE UPDATE ON dealer_wallet_transactions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet transactions are immutable'");
        DB::unprepared("CREATE TRIGGER dealer_wallet_transactions_no_delete BEFORE DELETE ON dealer_wallet_transactions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet transactions are immutable'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS dealer_wallet_transactions_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS dealer_wallet_transactions_no_update');
        Schema::dropIfExists('dealer_wallet_transactions');
    }
};
