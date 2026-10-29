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
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->index(['status', 'sort_order']);
        });
        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });
        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('symbol', 30);
            $table->unsignedTinyInteger('decimal_precision')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('product_code', 40)->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignId('product_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            $table->boolean('track_inventory')->default(false);
            $table->boolean('track_batch')->default(false);
            $table->boolean('track_expiry')->default(false);
            $table->decimal('default_low_stock_threshold', 18, 3)->nullable();
            $table->timestamps();
            $table->index(['status', 'product_category_id', 'brand_id']);
            $table->index('name');
        });
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('sku', 100)->unique();
            $table->string('variant_name');
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('barcode', 100)->nullable()->unique();
            $table->json('specifications')->nullable();
            $table->boolean('sellable_retail')->default(false);
            $table->boolean('sellable_dealer')->default(false);
            $table->boolean('clinic_material')->default(false);
            $table->boolean('track_inventory')->default(false);
            $table->boolean('track_batch')->default(false);
            $table->boolean('track_expiry')->default(false);
            $table->decimal('weight', 12, 3)->nullable();
            $table->decimal('length', 12, 3)->nullable();
            $table->decimal('width', 12, 3)->nullable();
            $table->decimal('height', 12, 3)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->index(['product_id', 'status', 'sellable_retail']);
        });
        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('path');
            $table->string('alt_text')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->index(['product_id', 'product_variant_id', 'sort_order']);
        });
        Schema::create('price_lists', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('pricing_context', 16)->default('retail');
            $table->string('scope_type', 16)->default('all');
            $table->char('currency', 3)->default('VND');
            $table->dateTime('effective_from')->nullable();
            $table->dateTime('effective_to')->nullable();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->index(['pricing_context', 'status', 'currency']);
        });
        Schema::create('price_list_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('price_list_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_price', 18, 2);
            $table->decimal('minimum_quantity', 18, 3)->default(1);
            $table->dateTime('effective_from')->nullable();
            $table->dateTime('effective_to')->nullable();
            $table->timestamps();
            $table->index(['product_variant_id', 'minimum_quantity']);
            $table->index(['price_list_id', 'product_variant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('units');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('product_categories');
    }
};
