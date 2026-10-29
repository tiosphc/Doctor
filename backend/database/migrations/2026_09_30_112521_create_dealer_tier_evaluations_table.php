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
        DB::table('dealer_tiers')->where('code', 'SILVER')->update(['revenue_threshold' => '0.00']);
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE dealer_tiers ADD CONSTRAINT dealer_silver_threshold_zero CHECK (code <> 'SILVER' OR (revenue_threshold IS NOT NULL AND revenue_threshold = 0))");
        }

        Schema::table('dealer_tier_histories', function (Blueprint $table): void {
            $table->char('evaluation_period', 7)->nullable();
            $table->date('revenue_period_start')->nullable();
            $table->date('revenue_period_end')->nullable();
            $table->timestamp('evaluated_at')->nullable();
        });

        Schema::create('dealer_tier_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dealer_account_id')->constrained('dealer_accounts')->restrictOnDelete();
            $table->char('evaluation_period', 7);
            $table->date('revenue_period_start');
            $table->date('revenue_period_end');
            $table->foreignId('previous_tier_id')->nullable()->constrained('dealer_tiers')->restrictOnDelete();
            $table->foreignId('target_tier_id')->nullable()->constrained('dealer_tiers')->restrictOnDelete();
            $table->decimal('settled_amount', 20, 2);
            $table->decimal('refunded_amount', 20, 2);
            $table->decimal('returned_amount', 20, 2);
            $table->decimal('net_revenue', 20, 2);
            $table->string('result', 32);
            $table->unsignedInteger('policy_version');
            $table->timestamp('evaluated_at');
            $table->timestamps();
            $table->unique(['dealer_account_id', 'evaluation_period'], 'dealer_tier_period_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('dealer_tier_evaluations')->exists()) {
            throw new RuntimeException('Review recorded Dealer Tier evaluations before rollback.');
        }
        Schema::dropIfExists('dealer_tier_evaluations');
        Schema::table('dealer_tier_histories', fn (Blueprint $table) => $table->dropColumn([
            'evaluation_period', 'revenue_period_start', 'revenue_period_end', 'evaluated_at',
        ]));
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE dealer_tiers DROP CHECK dealer_silver_threshold_zero');
        }
    }
};
