<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\DoctorTimeOff;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AestheticClinicSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'phone' => '0900000000',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        $customers = collect([
            ['name' => 'Minh Anh', 'email' => 'minh.anh@example.com', 'phone' => '0900000001'],
            ['name' => 'Thu Ha', 'email' => 'thu.ha@example.com', 'phone' => '0900000002'],
            ['name' => 'Quang Huy', 'email' => 'quang.huy@example.com', 'phone' => '0900000003'],
        ])->map(fn (array $customer) => User::factory()->create([
            ...$customer,
            'password' => Hash::make('password'),
            'role' => 'customer',
        ]));

        $doctors = collect([
            ['name' => 'Dr. Lan Nguyen', 'specialty' => 'Aesthetic Medicine', 'email' => 'lan.nguyen@example.com'],
            ['name' => 'Dr. Bao Tran', 'specialty' => 'Dermatology', 'email' => 'bao.tran@example.com'],
            ['name' => 'Dr. Linh Pham', 'specialty' => 'Cosmetic Consultation', 'email' => 'linh.pham@example.com'],
            ['name' => 'Dr. Nam Le', 'specialty' => 'Aesthetic Medicine', 'email' => 'nam.le@example.com'],
        ])->map(fn (array $doctor) => Doctor::factory()->create([
            ...$doctor,
            'bio' => 'Provides general aesthetic consultation services.',
            'status' => 'active',
        ]));

        $this->call(ServiceCatalogSeeder::class);

        $services = collect([
            'tu-van-da-chuyen-sau',
            'dieu-tri-mun-seo-ro',
            'cham-soc-phuc-hoi-lan-da',
            'tre-hoa-ultherapy-thermage',
            'tiem-botox-filler-chuan-y-khoa',
        ])->map(fn (string $slug): Service => Service::query()->where('slug', $slug)->firstOrFail());

        $doctors[0]->services()->attach([$services[0]->id, $services[1]->id, $services[4]->id]);
        $doctors[1]->services()->attach([$services[0]->id, $services[1]->id, $services[2]->id]);
        $doctors[2]->services()->attach([$services[0]->id, $services[2]->id, $services[3]->id]);
        $doctors[3]->services()->attach([$services[0]->id, $services[3]->id, $services[4]->id]);

        $this->seedSchedules($doctors->all());
        $this->seedTimeOffs($doctors->all());
        $this->seedAppointments($customers->all(), $doctors->all(), $services->all());
    }

    /** @param array<int, Doctor> $doctors */
    private function seedSchedules(array $doctors): void
    {
        foreach ($doctors as $index => $doctor) {
            $workDays = $index === 3 ? range(2, 6) : range(1, 5);

            foreach ($workDays as $dayOfWeek) {
                $periods = $index === 2
                    ? [['09:00:00', '12:00:00'], ['13:30:00', '18:00:00']]
                    : [['08:00:00', '12:00:00'], ['13:30:00', '17:30:00']];

                foreach ($periods as [$startTime, $endTime]) {
                    DoctorSchedule::create([
                        'doctor_id' => $doctor->id,
                        'day_of_week' => $dayOfWeek,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                    ]);
                }
            }
        }
    }

    /** @param array<int, Doctor> $doctors */
    private function seedTimeOffs(array $doctors): void
    {
        $futureMonday = CarbonImmutable::today()->addWeeks(2)->next(CarbonImmutable::MONDAY);

        DoctorTimeOff::create([
            'doctor_id' => $doctors[0]->id,
            'date' => $futureMonday,
            'reason' => 'Personal leave',
        ]);

        DoctorTimeOff::create([
            'doctor_id' => $doctors[2]->id,
            'date' => $futureMonday->addDays(2),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'reason' => 'Morning unavailable',
        ]);
    }

    /**
     * @param  array<int, User>  $customers
     * @param  array<int, Doctor>  $doctors
     * @param  array<int, Service>  $services
     */
    private function seedAppointments(array $customers, array $doctors, array $services): void
    {
        $nextMonday = CarbonImmutable::today()->next(CarbonImmutable::MONDAY);

        $appointments = [
            [$customers[0], $doctors[0], $services[0], $nextMonday, '08:30:00', '09:00:00', 'pending'],
            [$customers[1], $doctors[1], $services[1], $nextMonday->addDays(1), '09:00:00', '09:45:00', 'confirmed'],
            [$customers[2], $doctors[2], $services[2], $nextMonday->subWeek(), '13:30:00', '14:30:00', 'completed'],
            [$customers[0], $doctors[3], $services[4], $nextMonday->addDays(4), '14:00:00', '14:30:00', 'cancelled'],
        ];

        foreach ($appointments as [$customer, $doctor, $service, $date, $startTime, $endTime, $status]) {
            Appointment::factory()->create([
                'user_id' => $customer->id,
                'doctor_id' => $doctor->id,
                'service_id' => $service->id,
                'appointment_date' => $date,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'status' => $status,
                'note' => 'Demo appointment',
            ]);
        }
    }
}
