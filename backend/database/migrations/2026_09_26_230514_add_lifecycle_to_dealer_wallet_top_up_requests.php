<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dealer_wallet_top_up_requests', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('checkout_url');
            $table->timestamp('provider_checked_at')->nullable()->after('expires_at');
            $table->timestamp('expired_at')->nullable()->after('provider_checked_at');
            $table->timestamp('paid_at')->nullable()->after('provider_reference');
            $table->timestamp('cancelled_at')->nullable()->after('paid_at');
            $table->timestamp('failed_at')->nullable()->after('cancelled_at');
        });

        DB::table('dealer_wallet_top_up_requests')->where('status', 'paid')->whereNull('paid_at')
            ->update(['paid_at' => DB::raw('completed_at')]);
    }

    public function down(): void
    {
        Schema::table('dealer_wallet_top_up_requests', function (Blueprint $table): void {
            $table->dropColumn(['expires_at', 'provider_checked_at', 'expired_at', 'paid_at', 'cancelled_at', 'failed_at']);
        });
    }
};
