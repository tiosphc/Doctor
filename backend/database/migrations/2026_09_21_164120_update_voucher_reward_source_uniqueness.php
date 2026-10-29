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
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropUnique('vouchers_source_source_id_unique');
            $table->unique(
                ['user_id', 'source', 'source_id'],
                'vouchers_user_source_source_id_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropUnique('vouchers_user_source_source_id_unique');
            $table->unique(['source', 'source_id'], 'vouchers_source_source_id_unique');
        });
    }
};
