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
        Schema::create('dealer_wallet_deposit_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_code')->unique();
            $table->foreignId('dealer_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('dealer_wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 19, 2);
            $table->string('payment_proof_path');
            $table->string('transaction_reference')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('dealer_wallet_transaction_id')->nullable()->unique('wallet_deposit_request_transaction_unique');
            $table->foreign('dealer_wallet_transaction_id', 'wallet_deposit_request_transaction_fk')->references('id')->on('dealer_wallet_transactions')->restrictOnDelete();
            $table->uuid('operation_key')->unique();
            $table->timestamps();
            $table->index(['dealer_account_id', 'id']);
            $table->index(['status', 'id']);
        });
        DB::statement('ALTER TABLE dealer_wallet_deposit_requests ADD CONSTRAINT dealer_wallet_deposit_requests_amount_positive CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dealer_wallet_deposit_requests');
    }
};
