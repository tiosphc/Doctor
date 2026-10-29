<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('blog_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('slug')->unique();
            $table->timestamps();
        });

        $categoryIds = [];

        foreach (DB::table('blogs')->select('category')->distinct()->pluck('category') as $name) {
            if ($name === null) {
                throw new LogicException('Cannot migrate a blog with a null category.');
            }

            $name = (string) $name;
            $baseSlug = Str::slug($name) ?: 'category';
            $slug = $baseSlug;
            $suffix = 2;

            while (DB::table('blog_categories')->where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$suffix;
                $suffix++;
            }

            $categoryIds[$name] = DB::table('blog_categories')->insertGetId([
                'name' => $name,
                'slug' => $slug,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('blogs', function (Blueprint $table): void {
            $table->unsignedBigInteger('category_id')->nullable()->after('category');
        });

        foreach ($categoryIds as $name => $categoryId) {
            DB::table('blogs')
                ->where('category', $name)
                ->update(['category_id' => $categoryId]);
        }

        if (DB::table('blogs')->whereNull('category_id')->exists()) {
            throw new LogicException('Cannot migrate blogs without a category.');
        }

        Schema::table('blogs', function (Blueprint $table): void {
            $table->unsignedBigInteger('category_id')->nullable(false)->change();
            $table->foreign('category_id')
                ->references('id')
                ->on('blog_categories')
                ->restrictOnDelete();
        });

        Schema::table('blogs', function (Blueprint $table): void {
            $table->dropColumn('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blogs', function (Blueprint $table): void {
            $table->string('category', 100)->nullable()->after('slug');
        });

        $categoryNames = DB::table('blog_categories')->pluck('name', 'id');

        foreach ($categoryNames as $categoryId => $name) {
            DB::table('blogs')
                ->where('category_id', $categoryId)
                ->update(['category' => $name]);
        }

        if (DB::table('blogs')->whereNull('category')->exists()) {
            throw new LogicException('Cannot roll back blog categories while blog category values are missing.');
        }

        Schema::table('blogs', function (Blueprint $table): void {
            $table->string('category', 100)->nullable(false)->change();
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });

        Schema::dropIfExists('blog_categories');
    }
};
