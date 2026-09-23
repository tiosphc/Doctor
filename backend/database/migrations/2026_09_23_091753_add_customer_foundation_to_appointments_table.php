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
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('customer_id')
                ->nullable()
                ->after('user_id')
                ->constrained('customers')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('customer_name_snapshot')->nullable()->after('customer_id');
            $table->string('customer_email_snapshot')->nullable()->after('customer_name_snapshot');
            $table->string('customer_phone_snapshot', 30)->nullable()->after('customer_email_snapshot');

            $table->index(
                ['customer_id', 'appointment_date', 'id'],
                'appointments_customer_history_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropIndex('appointments_customer_history_index');
            $table->dropColumn([
                'customer_id',
                'customer_name_snapshot',
                'customer_email_snapshot',
                'customer_phone_snapshot',
            ]);
        });
    }
};
