<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\CustomerDetailResource;
use App\Http\Resources\Admin\CustomerResource;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Voucher;
use App\Services\LoyaltyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $customers = Customer::query()
            ->with('user:id,role')
            ->withCount([
                'appointments as completed_visits' => fn (Builder $query): Builder => $query
                    ->where('status', Appointment::STATUS_COMPLETED),
            ])
            ->addSelect([
                'last_appointment_date' => Appointment::query()
                    ->select('appointment_date')
                    ->whereColumn('appointments.customer_id', 'customers.id')
                    ->orderByDesc('appointment_date')
                    ->orderByDesc('id')
                    ->limit(1),
            ]);

        if (isset($validated['search'])) {
            $search = $validated['search'];

            $customers->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('primary_email', 'like', '%'.$search.'%')
                    ->orWhere('primary_phone', 'like', '%'.$search.'%')
                    ->orWhere('customer_code', 'like', '%'.$search.'%');
            });
        }

        return CustomerResource::collection(
            $customers->orderBy('name')->orderBy('id')->paginate(5)->withQueryString(),
        );
    }

    public function show(Customer $customer, LoyaltyService $loyaltyService): CustomerDetailResource
    {
        $customer->loadCount([
            'appointments',
            'appointments as completed_appointments_count' => fn (Builder $query): Builder => $query
                ->where('status', Appointment::STATUS_COMPLETED),
            'appointments as upcoming_appointments_count' => fn (Builder $query): Builder => $query
                ->whereIn('status', Appointment::BLOCKING_STATUSES)
                ->whereDate('appointment_date', '>=', now()->toDateString()),
            'appointments as cancelled_appointments_count' => fn (Builder $query): Builder => $query
                ->where('status', Appointment::STATUS_CANCELLED),
        ]);
        $customer->load('user');

        if ($customer->user !== null) {
            $customer->user->loadCount([
                'reviews',
                'vouchers as available_vouchers_count' => fn (Builder $query): Builder => $query
                    ->where('status', Voucher::STATUS_ACTIVE)
                    ->where('expires_at', '>=', now()),
            ])->load([
                'vouchers' => fn ($query) => $query
                    ->where('source', Voucher::SOURCE_LOYALTY_MILESTONE)
                    ->latest('created_at')
                    ->orderByDesc('id'),
            ]);
        }

        $loyalty = $customer->user === null
            ? [
                'completed_visits' => 0,
                'next_milestone' => null,
                'achieved_milestones' => [],
            ]
            : $loyaltyService->summary($customer->user);

        return new CustomerDetailResource($customer, $loyalty);
    }
}
