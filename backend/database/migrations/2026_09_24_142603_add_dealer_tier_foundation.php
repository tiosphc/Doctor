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
        Schema::table('dealer_tiers', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_default_initial')->default(false);
            $table->unsignedTinyInteger('active_initial_slot')->nullable()
                ->storedAs("case when `status` = 'active' and `is_default_initial` = 1 then 1 else null end")->unique();
        });

        Schema::table('dealer_accounts', function (Blueprint $table): void {
            $table->foreignId('current_tier_id')->nullable()->constrained('dealer_tiers')->restrictOnDelete();
        });

        Schema::create('dealer_tier_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dealer_account_id')->constrained('dealer_accounts')->restrictOnDelete();
            $table->foreignId('previous_tier_id')->nullable()->constrained('dealer_tiers')->restrictOnDelete();
            $table->foreignId('new_tier_id')->constrained('dealer_tiers')->restrictOnDelete();
            $table->string('source', 32);
            $table->text('reason')->nullable();
            $table->uuid('operation_key')->nullable()->unique();
            $table->timestamp('effective_at');
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['dealer_account_id', 'effective_at', 'id']);
        });

        Schema::create('dealer_tier_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dealer_account_id')->constrained('dealer_accounts')->restrictOnDelete();
            $table->foreignId('tier_id')->constrained('dealer_tiers')->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->text('reason');
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['dealer_account_id', 'status', 'starts_at', 'ends_at'], 'dealer_override_period_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER dealer_tiers_code_immutable BEFORE UPDATE ON dealer_tiers FOR EACH ROW BEGIN IF OLD.code <> NEW.code THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dealer Tier code is immutable'; END IF; END");
            DB::unprepared("CREATE TRIGGER dealer_tier_histories_no_update BEFORE UPDATE ON dealer_tier_histories FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dealer Tier history is immutable'");
            DB::unprepared("CREATE TRIGGER dealer_tier_histories_no_delete BEFORE DELETE ON dealer_tier_histories FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dealer Tier history is immutable'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('dealer_tier_histories')->exists()
            || DB::table('dealer_tier_overrides')->exists()
            || DB::table('dealer_accounts')->whereNotNull('current_tier_id')->exists()) {
            throw new RuntimeException('Review Dealer Tier assignments and history before rollback.');
        }
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS dealer_tier_histories_no_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS dealer_tier_histories_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS dealer_tiers_code_immutable');
        }
        Schema::dropIfExists('dealer_tier_overrides');
        Schema::dropIfExists('dealer_tier_histories');
        Schema::table('dealer_accounts', fn (Blueprint $table) => $table->dropConstrainedForeignId('current_tier_id'));
        Schema::table('dealer_tiers', fn (Blueprint $table) => $table->dropColumn(['sort_order', 'description', 'is_default_initial', 'active_initial_slot']));
    }
};
