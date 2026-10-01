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
        if (! Schema::hasTable('administrative_provinces')) {
            Schema::create('administrative_provinces', function (Blueprint $table): void {
                $table->string('code', 10)->primary();
                $table->string('name');
            });
        }
        if (! Schema::hasTable('administrative_wards')) {
            Schema::create('administrative_wards', function (Blueprint $table): void {
                $table->string('code', 10)->primary();
                $table->string('province_code', 10);
                $table->string('name');
                $table->foreign('province_code')->references('code')->on('administrative_provinces')->restrictOnDelete();
                $table->index(['province_code', 'name']);
            });
        }
        if (! Schema::hasTable('warehouse_service_areas')) {
            Schema::create('warehouse_service_areas', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
                $table->string('province_code', 10);
                $table->foreign('province_code')->references('code')->on('administrative_provinces')->restrictOnDelete();
                $table->unsignedSmallInteger('priority')->default(1);
                $table->unique(['warehouse_id', 'province_code']);
                $table->index(['province_code', 'priority']);
            });
        }
        if (! Schema::hasTable('dealer_shipping_addresses')) {
            Schema::create('dealer_shipping_addresses', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('dealer_account_id')->constrained()->cascadeOnDelete();
                $table->string('recipient_name');
                $table->string('recipient_phone', 50);
                $table->string('address_line');
                $table->string('province_code', 10);
                $table->string('ward_code', 10);
                $table->string('postal_code', 30)->nullable();
                $table->string('district_legacy')->nullable();
                $table->boolean('is_default')->default(false);
                $table->timestamps();
                $table->foreign('province_code')->references('code')->on('administrative_provinces')->restrictOnDelete();
                $table->foreign('ward_code')->references('code')->on('administrative_wards')->restrictOnDelete();
                $table->index(['dealer_account_id', 'is_default']);
            });
        }
        if (! Schema::hasColumn('sales_orders', 'shipping_province_code')) {
            Schema::table('sales_orders', function (Blueprint $table): void {
                $table->string('shipping_province_code', 10)->nullable();
                $table->string('shipping_ward_code', 10)->nullable();
                $table->string('shipping_ward')->nullable();
            });
        }

        $dataset = json_decode(file_get_contents(database_path('seeders/assets/vietnam-provinces-wards.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($dataset as $province) {
            DB::table('administrative_provinces')->insertOrIgnore(['code' => $province['code'], 'name' => $province['name']]);
            foreach (array_chunk($province['wards'], 200) as $wards) {
                DB::table('administrative_wards')->insertOrIgnore(array_map(static fn (array $ward): array => [
                    'code' => $ward['code'], 'province_code' => $province['code'], 'name' => $ward['name'],
                ], $wards));
            }
        }
        foreach (['1' => 'DEMO-WH-HN', '79' => 'DEMO-WH-HCM'] as $provinceCode => $warehouseCode) {
            $warehouseId = DB::table('warehouses')->where('code', $warehouseCode)->value('id');
            if ($warehouseId !== null) {
                DB::table('warehouse_service_areas')->insertOrIgnore([
                    'warehouse_id' => $warehouseId, 'province_code' => $provinceCode, 'priority' => 1,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropColumn(['shipping_province_code', 'shipping_ward_code', 'shipping_ward']);
        });
        Schema::dropIfExists('dealer_shipping_addresses');
        Schema::dropIfExists('warehouse_service_areas');
        Schema::dropIfExists('administrative_wards');
        Schema::dropIfExists('administrative_provinces');
    }
};
