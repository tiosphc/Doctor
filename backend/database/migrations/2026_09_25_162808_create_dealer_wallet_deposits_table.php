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
        Schema::create('dealer_wallet_deposits', function (Blueprint $table) {
            $table->id();
            $table->string('deposit_code')->unique();
            $table->foreignId('dealer_wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('dealer_account_id')->constrained()->restrictOnDelete();
            $table->char('currency', 3);
            $table->decimal('amount', 19, 2);
            $table->enum('method', ['bank_transfer', 'cash', 'other_manual']);
            $table->string('external_reference')->nullable();
            $table->string('external_reference_normalized')->nullable();
            $table->text('note')->nullable();
            $table->enum('status', ['completed'])->default('completed');
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_key')->unique();
            $table->string('request_fingerprint', 64);
            $table->timestamp('completed_at');
            $table->timestamps();
            $table->unique(['method', 'external_reference_normalized'], 'wallet_deposit_method_reference_unique');
        });
        DB::statement('ALTER TABLE dealer_wallet_deposits ADD CONSTRAINT dealer_wallet_deposits_amount_positive CHECK (amount > 0)');
        DB::unprepared("CREATE TRIGGER dealer_wallet_deposits_no_update BEFORE UPDATE ON dealer_wallet_deposits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet deposits are immutable'");
        DB::unprepared("CREATE TRIGGER dealer_wallet_deposits_no_delete BEFORE DELETE ON dealer_wallet_deposits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet deposits are immutable'");
        Schema::table('dealer_wallet_transactions', function (Blueprint $table): void {
            $table->foreign('dealer_wallet_deposit_id')->references('id')->on('dealer_wallet_deposits')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS dealer_wallet_deposits_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS dealer_wallet_deposits_no_update');
        if (Schema::hasTable('dealer_wallet_transactions')) {
            Schema::table('dealer_wallet_transactions', function (Blueprint $table): void {
                $table->dropForeign(['dealer_wallet_deposit_id']);
            });
        }
        Schema::dropIfExists('dealer_wallet_deposits');
    }
};
