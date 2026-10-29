<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS appointments_owner_before_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS appointments_owner_before_update');

        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('booking_code', 32)->nullable()->change();
        });

        DB::table('appointments')
            ->whereNull('booking_code')
            ->orderBy('id')
            ->chunkById(200, function ($appointments): void {
                foreach ($appointments as $appointment) {
                    DB::table('appointments')
                        ->where('id', $appointment->id)
                        ->update(['booking_code' => $this->uniqueLegacyCode(
                            Carbon::parse($appointment->created_at),
                        )]);
                }
            });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('booking_code', 32)->nullable(false)->change();
        });

        DB::unprepared($this->ownershipTriggerSql('appointments_owner_before_insert', 'INSERT'));
        DB::unprepared($this->ownershipTriggerSql('appointments_owner_before_update', 'UPDATE'));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Booking codes are public immutable identifiers and cannot be safely removed.');
    }

    private function uniqueLegacyCode(Carbon $createdAt): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $suffix = $alphabet[random_int(0, strlen($alphabet) - 1)]
                .$alphabet[random_int(0, strlen($alphabet) - 1)];
            $code = 'JUN-'.$createdAt->format('Ymd-His-v').'-'.$suffix;

            if (! DB::table('appointments')->where('booking_code', $code)->exists()) {
                return $code;
            }
        }

        throw new LogicException('Unable to generate a unique booking code during backfill.');
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
                        AND NEW.booking_code IS NOT NULL
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
