<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Notifications\AppointmentNotification;
use App\Services\AppointmentNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

#[Signature('appointments:send-reminders')]
#[Description('Send reminders for upcoming confirmed appointments')]
class SendAppointmentReminders extends Command
{
    public function handle(AppointmentNotificationService $notificationService): int
    {
        $now = CarbonImmutable::now();
        $leadMinutes = max(0, (int) config('booking.notifications.reminder_lead_minutes', 1440));
        $batchSize = max(1, (int) config('booking.notifications.reminder_batch_size', 100));
        $windowEnd = $now->addMinutes($leadMinutes);
        $sent = 0;

        $this->eligibleAppointments($now, $windowEnd)
            ->select('id')
            ->chunkById($batchSize, function ($appointments) use ($notificationService, $now, $windowEnd, &$sent): void {
                foreach ($appointments as $candidate) {
                    $didSend = DB::transaction(function () use ($candidate, $notificationService, $now, $windowEnd): bool {
                        $appointment = Appointment::query()
                            ->with(['user', 'doctor', 'service'])
                            ->whereKey($candidate->id)
                            ->lockForUpdate()
                            ->first();

                        if ($appointment === null || ! $this->isEligible($appointment, $now, $windowEnd)) {
                            return false;
                        }

                        $appointment->update(['reminder_sent_at' => now()]);
                        $notificationService->send($appointment, AppointmentNotification::EVENT_REMINDER);

                        return true;
                    });

                    if ($didSend) {
                        $sent++;
                    }
                }
            });

        $this->info("Sent {$sent} appointment reminder(s).");

        return self::SUCCESS;
    }

    /** @return Builder<Appointment> */
    private function eligibleAppointments(CarbonImmutable $now, CarbonImmutable $windowEnd): Builder
    {
        return Appointment::query()
            ->where('status', Appointment::STATUS_CONFIRMED)
            ->whereNull('reminder_sent_at')
            ->where(function (Builder $query) use ($now, $windowEnd): void {
                $query->where(function (Builder $sameDay) use ($now): void {
                    $sameDay->whereDate('appointment_date', $now->toDateString())
                        ->where('start_time', '>', $now->format('H:i:s'));
                })->orWhere(function (Builder $betweenDays) use ($now, $windowEnd): void {
                    $betweenDays->whereDate('appointment_date', '>', $now->toDateString())
                        ->whereDate('appointment_date', '<', $windowEnd->toDateString());
                })->orWhere(function (Builder $endDay) use ($windowEnd): void {
                    $endDay->whereDate('appointment_date', $windowEnd->toDateString())
                        ->where('start_time', '<=', $windowEnd->format('H:i:s'));
                });
            });
    }

    private function isEligible(Appointment $appointment, CarbonImmutable $now, CarbonImmutable $windowEnd): bool
    {
        if ($appointment->status !== Appointment::STATUS_CONFIRMED || $appointment->reminder_sent_at !== null) {
            return false;
        }

        $startsAt = CarbonImmutable::parse(
            $appointment->appointment_date->toDateString().' '.$appointment->start_time,
        );

        return $startsAt->gt($now) && $startsAt->lte($windowEnd);
    }
}
