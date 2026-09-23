<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->text('short_description')->nullable()->after('description');
            $table->string('duration_note')->nullable()->after('duration');
            $table->string('price_note')->nullable()->after('price');
            $table->string('hero_image')->nullable()->after('image');
            $table->text('hero_disclaimer')->nullable()->after('hero_image');
            $table->string('cta_label')->nullable()->after('hero_disclaimer');
            $table->unsignedInteger('sort_order')->default(0)->after('status');
            $table->string('seo_title')->nullable()->after('sort_order');
            $table->text('seo_description')->nullable()->after('seo_title');
            $table->json('content')->nullable()->after('seo_description');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn([
                'short_description',
                'duration_note',
                'price_note',
                'hero_image',
                'hero_disclaimer',
                'cta_label',
                'sort_order',
                'seo_title',
                'seo_description',
                'content',
            ]);
        });
    }
};
