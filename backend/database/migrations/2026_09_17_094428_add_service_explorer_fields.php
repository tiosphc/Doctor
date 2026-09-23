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
        Schema::table('service_categories', function (Blueprint $table): void {
            $table->text('short_description')->nullable()->after('name');
            $table->string('hero_image')->nullable()->after('short_description');
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->string('slug')->nullable()->after('name');
        });

        $usedSlugs = [];

        foreach (DB::table('services')->select(['id', 'name'])->orderBy('id')->get() as $service) {
            $baseSlug = Str::slug((string) $service->name) ?: 'service';
            $slug = $baseSlug;
            $suffix = 2;

            while (isset($usedSlugs[$slug])) {
                $slug = $baseSlug.'-'.$suffix;
                $suffix++;
            }

            DB::table('services')
                ->where('id', $service->id)
                ->update(['slug' => $slug]);

            $usedSlugs[$slug] = true;
        }

        if (DB::table('services')->whereNull('slug')->exists()) {
            throw new LogicException('Cannot migrate services without a slug.');
        }

        Schema::table('services', function (Blueprint $table): void {
            $table->string('slug')->nullable(false)->change();
            $table->unique('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });

        Schema::table('service_categories', function (Blueprint $table): void {
            $table->dropColumn(['short_description', 'hero_image']);
        });
    }
};
