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
        Schema::table('dealer_tiers', function (Blueprint $table): void {
            $table->decimal('revenue_threshold', 20, 2)->nullable();
        });

        Schema::table('dealer_tier_histories', function (Blueprint $table): void {
            $table->decimal('net_revenue_snapshot', 20, 2)->nullable();
            $table->unsignedInteger('policy_version')->nullable();
        });

        Schema::create('dealer_auto_tier_policies', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('enabled_at')->nullable();
            $table->foreignId('enabled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        DB::table('dealer_auto_tier_policies')->insert([
            'id' => 1, 'enabled' => false, 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('dealer_tier_histories')->where('source', 'automatic_revenue')->exists()) {
            throw new RuntimeException('Review automatic Dealer Tier history before rollback.');
        }
        Schema::dropIfExists('dealer_auto_tier_policies');
        Schema::table('dealer_tier_histories', fn (Blueprint $table) => $table->dropColumn(['net_revenue_snapshot', 'policy_version']));
        Schema::table('dealer_tiers', fn (Blueprint $table) => $table->dropColumn('revenue_threshold'));
    }
};
