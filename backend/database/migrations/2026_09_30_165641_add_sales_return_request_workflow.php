<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->string('request_source', 16)->nullable()->after('status');
            $table->foreignId('requested_by_user_id')->nullable()->after('request_source')->constrained('users')->restrictOnDelete();
            $table->string('reason_code', 32)->nullable()->after('reason');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('rejected_at')->nullable();
            $table->string('rejection_reason', 1000)->nullable();
            $table->dateTime('received_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->dropForeign(['requested_by_user_id']);
            $table->dropForeign(['approved_by_user_id']);
            $table->dropForeign(['rejected_by_user_id']);
            $table->dropColumn(['request_source', 'requested_by_user_id', 'reason_code', 'approved_by_user_id', 'approved_at', 'rejected_by_user_id', 'rejected_at', 'rejection_reason', 'received_at']);
        });
    }
};
