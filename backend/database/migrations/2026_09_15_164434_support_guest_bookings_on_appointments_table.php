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
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('guest_name')->nullable()->after('user_id');
            $table->string('guest_email')->nullable()->after('guest_name');
            $table->string('guest_phone', 30)->nullable()->after('guest_email');
            $table->string('booking_code', 20)->nullable()->unique()->after('guest_phone');
            $table->index(
                ['guest_email', 'appointment_date', 'status'],
                'appointments_guest_active_index',
            );
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });

        DB::unprepared($this->ownershipTriggerSql('appointments_owner_before_insert', 'INSERT'));
        DB::unprepared($this->ownershipTriggerSql('appointments_owner_before_update', 'UPDATE'));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('appointments')->whereNull('user_id')->exists()) {
            throw new LogicException('Cannot roll back guest booking support while guest appointments exist.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS appointments_owner_before_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS appointments_owner_before_update');

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex('appointments_guest_active_index');
            $table->dropUnique(['booking_code']);
            $table->dropColumn(['guest_name', 'guest_email', 'guest_phone', 'booking_code']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    private function ownershipTriggerSql(string $name, string $event): string
    {
        return <<<SQL
            CREATE TRIGGER {$name}
            BEFORE {$event} ON appointments
            FOR EACH ROW
            BEGIN
                IF NOT (
                    (
                        NEW.user_id IS NOT NULL
                        AND NEW.guest_name IS NULL
                        AND NEW.guest_email IS NULL
                        AND NEW.guest_phone IS NULL
                        AND NEW.booking_code IS NULL
                    )
                    OR
                    (
                        NEW.user_id IS NULL
                        AND NEW.guest_name IS NOT NULL
                        AND NEW.guest_email IS NOT NULL
                        AND NEW.guest_phone IS NOT NULL
                        AND NEW.booking_code IS NOT NULL
                    )
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Appointment must belong to one registered customer or one guest';
                END IF;
            END
        SQL;
    }
};
