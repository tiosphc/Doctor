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
        Schema::create('service_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->unsignedBigInteger('category_id')->nullable()->after('id');
        });

        $serviceCategoryId = null;

        if (DB::table('services')->exists()) {
            $serviceCategoryId = DB::table('service_categories')->insertGetId([
                'name' => 'Chưa phân loại',
                'slug' => 'chua-phan-loai',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($serviceCategoryId !== null) {
            DB::table('services')->update(['category_id' => $serviceCategoryId]);
        }

        if (DB::table('services')->whereNull('category_id')->exists()) {
            throw new LogicException('Cannot migrate services without a category.');
        }

        Schema::table('services', function (Blueprint $table): void {
            $table->unsignedBigInteger('category_id')->nullable(false)->change();
            $table->foreign('category_id')
                ->references('id')
                ->on('service_categories')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });

        Schema::dropIfExists('service_categories');
    }
};
