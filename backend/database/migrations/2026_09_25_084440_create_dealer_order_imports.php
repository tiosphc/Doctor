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
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->string('external_reference', 100)->nullable();
            $table->string('external_reference_normalized', 100)->nullable();
            $table->unique(['dealer_account_id', 'external_reference_normalized'], 'sales_order_dealer_external_ref_unique');
        });
        Schema::create('dealer_order_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dealer_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('original_filename');
            $table->char('file_hash', 64);
            $table->unsignedInteger('file_size');
            $table->string('import_mode', 24)->default('multi_order');
            $table->string('status', 32)->default('preview_ready');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('order_count')->default(0);
            $table->unsignedInteger('valid_order_count')->default(0);
            $table->unsignedInteger('invalid_order_count')->default(0);
            $table->char('preview_fingerprint', 64)->nullable();
            $table->string('confirm_operation_key', 36)->nullable();
            $table->char('confirm_payload_fingerprint', 64)->nullable();
            $table->json('preview_summary')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['dealer_account_id', 'uploaded_by', 'created_at'], 'dealer_import_owner_idx');
            $table->index(['dealer_account_id', 'file_hash', 'status'], 'dealer_import_hash_idx');
        });
        Schema::create('dealer_order_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dealer_order_import_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sheet_row_number');
            $table->string('external_reference', 100)->nullable();
            $table->string('external_reference_normalized', 100)->nullable();
            $table->string('sku_input', 100)->nullable();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('quantity', 30)->nullable();
            $table->json('recipient');
            $table->json('validation_errors')->nullable();
            $table->timestamps();
            $table->unique(['dealer_order_import_id', 'sheet_row_number'], 'dealer_import_row_unique');
        });
        Schema::create('dealer_order_import_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dealer_order_import_id')->constrained()->restrictOnDelete();
            $table->string('external_reference', 100);
            $table->string('external_reference_normalized', 100);
            $table->string('status', 32)->default('invalid');
            $table->char('review_fingerprint', 64)->nullable();
            $table->json('preview')->nullable();
            $table->foreignId('sales_order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
            $table->unique(['dealer_order_import_id', 'external_reference_normalized'], 'dealer_import_group_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dealer_order_import_groups');
        Schema::dropIfExists('dealer_order_import_rows');
        Schema::dropIfExists('dealer_order_imports');
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropUnique('sales_order_dealer_external_ref_unique');
            $table->dropColumn(['external_reference', 'external_reference_normalized']);
        });
    }
};
