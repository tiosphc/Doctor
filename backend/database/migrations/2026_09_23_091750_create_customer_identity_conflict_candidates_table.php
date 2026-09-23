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
        Schema::create('customer_identity_conflict_candidates', function (Blueprint $table) {
            $table->foreignId('conflict_id')
                ->constrained('customer_identity_conflicts')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('match_basis', 30);
            $table->string('confidence', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['conflict_id', 'customer_id', 'match_basis'],
                'customer_conflict_candidates_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_identity_conflict_candidates');
    }
};
