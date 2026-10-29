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
        Schema::create('dealer_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('company_name');
            $table->string('trading_name')->nullable();
            $table->string('contact_name');
            $table->string('email');
            $table->string('phone', 32);
            $table->string('tax_code', 50)->nullable();
            $table->string('business_address_line1');
            $table->string('business_address_line2')->nullable();
            $table->string('city', 120);
            $table->string('province', 120);
            $table->string('country', 120);
            $table->string('postal_code', 30)->nullable();
            $table->string('business_type', 80)->nullable();
            $table->decimal('estimated_monthly_purchase', 18, 2)->nullable();
            $table->text('note')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('pending_user_id')->nullable()->storedAs("case when `status` = 'pending' then `user_id` else null end")->unique();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('dealer_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('legal_name');
            $table->string('trading_name')->nullable();
            $table->string('contact_name');
            $table->string('email');
            $table->string('phone', 32);
            $table->string('tax_code', 50)->nullable();
            $table->string('billing_address_line1');
            $table->string('billing_address_line2')->nullable();
            $table->string('city', 120);
            $table->string('province', 120);
            $table->string('country', 120);
            $table->string('postal_code', 30)->nullable();
            $table->string('status', 16)->default('active');
            $table->foreignId('source_application_id')->unique()->constrained('dealer_applications')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('inactivated_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['phone', 'status']);
            $table->index('tax_code');
        });

        Schema::create('dealer_account_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dealer_account_id')->constrained('dealer_accounts')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('membership_role', 32);
            $table->string('status', 16)->default('active');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['dealer_account_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER dealer_accounts_code_immutable BEFORE UPDATE ON dealer_accounts FOR EACH ROW BEGIN IF OLD.code <> NEW.code AND NOT (OLD.code LIKE 'TMP-%' AND NEW.code = CONCAT('DLR', LPAD(OLD.id, 8, '0'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dealer code is immutable'; END IF; END");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('dealer_applications')->exists()
            || DB::table('dealer_accounts')->exists()
            || DB::table('dealer_account_users')->exists()) {
            throw new RuntimeException('Review historical Dealer applications, accounts and memberships before rollback.');
        }
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS dealer_accounts_code_immutable');
        }
        Schema::dropIfExists('dealer_account_users');
        Schema::dropIfExists('dealer_accounts');
        Schema::dropIfExists('dealer_applications');
    }
};
