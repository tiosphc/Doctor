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
        Schema::create('dealer_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_account_id')->constrained()->restrictOnDelete();
            $table->char('currency', 3)->default('VND');
            $table->decimal('balance', 19, 2)->default(0);
            $table->timestamps();
            $table->unique(['dealer_account_id', 'currency']);
        });
        DB::statement('ALTER TABLE dealer_wallets ADD CONSTRAINT dealer_wallets_balance_nonnegative CHECK (balance >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dealer_wallets');
    }
};
