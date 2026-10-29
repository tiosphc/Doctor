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
        Schema::table('doctors', function (Blueprint $table) {
            $table->unsignedSmallInteger('baseline_review_count')->default(100)->after('status');
        });

        DB::table('doctors')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($doctors): void {
                foreach ($doctors as $doctor) {
                    DB::table('doctors')
                        ->where('id', $doctor->id)
                        ->update(['baseline_review_count' => random_int(100, 199)]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->dropColumn('baseline_review_count');
        });
    }
};
