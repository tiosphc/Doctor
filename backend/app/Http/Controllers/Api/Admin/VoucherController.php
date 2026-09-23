<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\BusinessConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreVoucherRequest;
use App\Http\Requests\Admin\VoucherIndexRequest;
use App\Http\Resources\VoucherResource;
use App\Models\Appointment;
use App\Models\Voucher;
use App\Services\AuditLogger;
use App\Services\VoucherService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class VoucherController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(VoucherIndexRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $vouchers = Voucher::query()->with(['user:id,name,email', 'usedAppointment'])
            ->when($validated['search'] ?? null, fn (Builder $query, string $search): Builder => $query->where('code', 'like', '%'.$search.'%'))
            ->when($validated['status'] ?? null, function (Builder $query, string $status): void {
                if ($status === Voucher::STATUS_ACTIVE) {
                    $query->where('status', $status)->where('expires_at', '>=', now());
                } elseif ($status === Voucher::STATUS_EXPIRED) {
                    $query->where(fn (Builder $expired): Builder => $expired
                        ->where('status', Voucher::STATUS_EXPIRED)
                        ->orWhere(fn (Builder $active): Builder => $active
                            ->where('status', Voucher::STATUS_ACTIVE)
                            ->where('expires_at', '<', now())));
                } else {
                    $query->where('status', $status);
                }
            })
            ->when($validated['source'] ?? null, fn (Builder $query, string $source): Builder => $query->where('source', $source))
            ->latest('created_at')->orderByDesc('id')->paginate(10)->withQueryString();

        return VoucherResource::collection($vouchers);
    }

    public function generateCode(VoucherService $voucherService): JsonResponse
    {
        return response()->json(['code' => $voucherService->generateAdminCode()]);
    }

    public function store(
        StoreVoucherRequest $request,
        VoucherService $voucherService,
    ): JsonResponse {
        $validated = $request->validated();

        $voucher = DB::transaction(function () use ($request, $validated, $voucherService): Voucher {
            $voucher = $voucherService->createAdminVoucher(
                $validated['code'],
                (float) $validated['value'],
                $validated['expires_at'],
            );

            $this->auditLogger->log(
                AuditLogger::ACTION_CREATE,
                AuditLogger::MODULE_VOUCHER,
                $voucher,
                $request->user()->name." đã tạo voucher {$voucher->code}.",
                newValues: [
                    'code' => $voucher->code,
                    'value' => $voucher->value,
                    'expires_at' => $voucher->expires_at,
                    'source' => $voucher->source,
                ],
            );

            return $voucher;
        }, 3);

        return (new VoucherResource($voucher->load(['user:id,name,email', 'usedAppointment'])))
            ->additional(['message' => 'Tạo voucher thành công.'])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function revoke(Voucher $voucher): VoucherResource
    {
        $voucher = DB::transaction(function () use ($voucher): Voucher {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === Voucher::STATUS_USED) {
                throw new BusinessConflictException('Voucher đã sử dụng không thể bị thu hồi.');
            }
            $previousStatus = $locked->status;
            $locked->update(['status' => Voucher::STATUS_REVOKED]);
            if ($previousStatus !== Voucher::STATUS_REVOKED) {
                $this->auditLogger->log(
                    AuditLogger::ACTION_DEACTIVATE,
                    AuditLogger::MODULE_VOUCHER,
                    $locked,
                    request()->user()->name." đã thu hồi voucher {$locked->code}.",
                    oldValues: ['status' => $previousStatus],
                    newValues: ['status' => Voucher::STATUS_REVOKED],
                );
            }

            return $locked->refresh();
        }, 3);

        return new VoucherResource($voucher->load(['user:id,name,email', 'usedAppointment']));
    }

    public function destroy(Voucher $voucher): JsonResponse
    {
        abort_unless($voucher->source === Voucher::SOURCE_ADMIN, Response::HTTP_NOT_FOUND);

        DB::transaction(function () use ($voucher): void {
            $locked = Voucher::query()
                ->with('usedAppointment')
                ->whereKey($voucher->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === Voucher::STATUS_USED
                || $locked->used_at !== null
                || Appointment::query()->where('voucher_id', $locked->id)->exists()) {
                throw new BusinessConflictException('Voucher đã được sử dụng và không thể xóa.');
            }

            $this->auditLogger->log(
                AuditLogger::ACTION_DELETE,
                AuditLogger::MODULE_VOUCHER,
                $locked,
                request()->user()->name." đã xóa voucher {$locked->code}.",
                oldValues: [
                    'code' => $locked->code,
                    'value' => $locked->value,
                    'expires_at' => $locked->expires_at,
                    'source' => $locked->source,
                    'status' => $locked->status,
                ],
                targetName: $locked->code,
            );

            $locked->delete();
        }, 3);

        return response()->json(['message' => 'Xóa voucher thành công.']);
    }
}
