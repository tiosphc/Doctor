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
        Schema::table('warehouses', function (Blueprint $table) {
            $table->decimal('dealer_price_adjustment_percent', 5, 2)->default(0);
        });

        DB::table('warehouses')->where('code', 'DEMO-WH-HN')
            ->update(['dealer_price_adjustment_percent' => '3.00']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('dealer_price_adjustment_percent');
        });
    }
};
