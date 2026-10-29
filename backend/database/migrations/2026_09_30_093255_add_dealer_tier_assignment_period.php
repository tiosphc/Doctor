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
        Schema::table('dealer_accounts', function (Blueprint $table): void {
            $table->timestamp('tier_assigned_at')->nullable()->after('current_tier_id');
            $table->timestamp('tier_expires_at')->nullable()->after('tier_assigned_at');
        });

        Schema::table('dealer_tier_histories', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('effective_at');
        });

        $initialExpiry = now()->addMonthNoOverflow();
        DB::table('dealer_accounts')->whereNotNull('current_tier_id')->orderBy('id')->chunkById(100, function ($accounts) use ($initialExpiry): void {
            foreach ($accounts as $account) {
                $assignedAt = DB::table('dealer_tier_histories')
                    ->where('dealer_account_id', $account->id)
                    ->orderByDesc('id')
                    ->value('effective_at');
                DB::table('dealer_accounts')->where('id', $account->id)->update([
                    'tier_assigned_at' => $assignedAt ?? $account->activated_at ?? $account->created_at,
                    'tier_expires_at' => $initialExpiry,
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dealer_tier_histories', function (Blueprint $table): void {
            $table->dropColumn('expires_at');
        });

        Schema::table('dealer_accounts', function (Blueprint $table): void {
            $table->dropColumn(['tier_assigned_at', 'tier_expires_at']);
        });
    }
};
