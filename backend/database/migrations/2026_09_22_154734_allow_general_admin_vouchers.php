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
        Schema::table('vouchers', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->unsignedBigInteger('source_id')->nullable()->change();
            $table->index(
                ['source', 'status', 'expires_at'],
                'vouchers_source_status_expires_at_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('vouchers')->whereNull('user_id')->orWhereNull('source_id')->exists()) {
            throw new RuntimeException(
                'Cannot restore required voucher ownership while general Admin vouchers exist.',
            );
        }

        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropIndex('vouchers_source_status_expires_at_index');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->unsignedBigInteger('source_id')->nullable(false)->change();
        });
    }
};
