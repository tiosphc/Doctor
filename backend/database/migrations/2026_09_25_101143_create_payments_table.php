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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_code', 32)->unique();
            $table->string('payment_context', 16);
            $table->foreignId('dealer_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payer_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('currency', 3);
            $table->decimal('amount', 18, 2);
            $table->string('payment_method', 32);
            $table->string('status', 16);
            $table->string('external_reference', 255)->nullable();
            $table->string('external_reference_normalized', 255)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_key')->unique();
            $table->char('request_fingerprint', 64);
            $table->dateTime('settled_at')->nullable();
            $table->timestamps();
            $table->unique(['payment_method', 'external_reference_normalized'], 'payment_method_reference_unique');
            $table->index(['dealer_account_id', 'status', 'created_at']);
            $table->index(['payment_context', 'status', 'created_at']);
        });
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payment_amount_positive CHECK (amount > 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payment_context_check CHECK ((payment_context = 'retail' AND dealer_account_id IS NULL) OR (payment_context = 'dealer' AND dealer_account_id IS NOT NULL))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payment_status_check CHECK (status IN ('pending', 'settled', 'cancelled', 'failed'))");
        DB::unprepared("CREATE TRIGGER payments_settled_no_update BEFORE UPDATE ON payments FOR EACH ROW BEGIN IF OLD.status = 'settled' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Settled payments are immutable'; END IF; END");
        DB::unprepared("CREATE TRIGGER payments_settled_no_delete BEFORE DELETE ON payments FOR EACH ROW BEGIN IF OLD.status = 'settled' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Settled payments are immutable'; END IF; END");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS payments_settled_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS payments_settled_no_update');
        Schema::dropIfExists('payments');
    }
};
