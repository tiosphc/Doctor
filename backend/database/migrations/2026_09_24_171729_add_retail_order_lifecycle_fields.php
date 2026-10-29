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
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->string('shipping_district')->nullable()->after('shipping_city');
            $table->string('payment_method', 32)->nullable()->after('payment_status');
            $table->foreignId('voucher_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('voucher_code_snapshot', 32)->nullable();
        });
        Schema::table('sales_order_items', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('image_path_snapshot')->nullable();
        });
        Schema::table('carts', function (Blueprint $table): void {
            $table->string('voucher_code', 32)->nullable();
        });
        Schema::create('sales_order_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 32);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->string('note', 1000)->nullable();
            $table->timestamps();
            $table->index(['sales_order_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_order_histories');
        Schema::table('carts', fn (Blueprint $table) => $table->dropColumn('voucher_code'));
        Schema::table('sales_order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn('image_path_snapshot');
        });
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('voucher_id');
            $table->dropColumn(['shipping_district', 'payment_method', 'voucher_code_snapshot']);
        });
    }
};
