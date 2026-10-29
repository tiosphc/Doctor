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
        Schema::table('price_list_items', function (Blueprint $table): void {
            $table->string('status', 16)->default('active')->after('minimum_quantity');
            $table->index(['product_variant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('price_list_items', function (Blueprint $table): void {
            $table->dropIndex(['product_variant_id', 'status']);
            $table->dropColumn('status');
        });
    }
};
