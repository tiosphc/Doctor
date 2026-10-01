<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dealer_wallet_top_up_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dealer_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->char('currency', 3)->default('VND');
            $table->decimal('amount', 19, 2);
            $table->string('provider', 20)->default('payos');
            $table->string('status', 20)->default('initiating');
            $table->uuid('operation_key')->unique();
            $table->char('request_fingerprint', 64);
            $table->unsignedBigInteger('provider_order_code')->nullable()->unique();
            $table->string('provider_payment_link_id')->nullable()->unique();
            $table->string('checkout_url', 2048)->nullable();
            $table->string('provider_reference')->nullable()->unique();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['dealer_account_id', 'id']);
        });
        DB::statement('ALTER TABLE dealer_wallet_top_up_requests ADD CONSTRAINT dealer_wallet_top_up_amount_positive CHECK (amount > 0)');
        DB::statement("ALTER TABLE dealer_wallet_deposits MODIFY method ENUM('bank_transfer', 'cash', 'other_manual', 'payos') NOT NULL");
        DB::statement('ALTER TABLE dealer_wallet_deposits MODIFY recorded_by_user_id BIGINT UNSIGNED NULL');
        Schema::table('dealer_wallet_deposits', function (Blueprint $table): void {
            $table->foreignId('dealer_wallet_top_up_request_id')->nullable()->unique()
                ->constrained('dealer_wallet_top_up_requests')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('dealer_wallet_deposits')->where('method', 'payos')->exists()) {
            throw new RuntimeException('Cannot roll back PayOS top-ups while completed deposits exist.');
        }

        Schema::table('dealer_wallet_deposits', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('dealer_wallet_top_up_request_id');
        });
        Schema::dropIfExists('dealer_wallet_top_up_requests');
        DB::statement("ALTER TABLE dealer_wallet_deposits MODIFY method ENUM('bank_transfer', 'cash', 'other_manual') NOT NULL");
        DB::statement('ALTER TABLE dealer_wallet_deposits MODIFY recorded_by_user_id BIGINT UNSIGNED NOT NULL');
    }
};
