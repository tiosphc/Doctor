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
        Schema::table('products', function (Blueprint $table): void {
            $table->string('name')->nullable()->change();
            $table->foreignId('product_category_id')->nullable()->change();
            $table->string('base_sku', 100)->nullable()->unique();
            $table->uuid('wizard_key')->nullable()->unique();
            $table->foreignId('wizard_owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('wizard_data')->nullable();
            $table->json('youtube_videos')->nullable();
            $table->text('usage_instructions')->nullable();
        });

        Schema::create('dealer_tiers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });

        Schema::table('price_lists', function (Blueprint $table): void {
            $table->foreignId('dealer_tier_id')->nullable()->constrained('dealer_tiers')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('products')->whereNull('name')->orWhereNull('product_category_id')->exists()
            || DB::table('dealer_tiers')->exists()
            || DB::table('price_lists')->whereNotNull('dealer_tier_id')->exists()) {
            throw new RuntimeException('Resolve incomplete Product drafts and Dealer tier data before rollback.');
        }

        Schema::table('price_lists', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('dealer_tier_id');
        });
        Schema::dropIfExists('dealer_tiers');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('wizard_owner_user_id');
            $table->dropColumn(['base_sku', 'wizard_key', 'wizard_data', 'youtube_videos', 'usage_instructions']);
            $table->string('name')->nullable(false)->change();
            $table->foreignId('product_category_id')->nullable(false)->change();
        });
    }
};
