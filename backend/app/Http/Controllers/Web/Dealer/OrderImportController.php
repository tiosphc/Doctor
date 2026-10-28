<?php

namespace App\Http\Controllers\Web\Dealer;

use App\Http\Controllers\Controller;
use App\Models\DealerAccount;
use App\Models\DealerOrderImport;
use App\Models\User;
use App\Services\DealerContextService;
use App\Services\DealerOrderImportService;
use App\Services\DealerOrderImportWorkbook;
use App\Services\DealerTierService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OrderImportController extends Controller
{
    public function index(Request $request, DealerContextService $context, DealerTierService $tiers): View
    {
        $account = $this->account($request, $context);
        $imports = DealerOrderImport::query()->where('dealer_account_id', $account->id)
            ->where('uploaded_by', $request->user()->id)->latest('id')->paginate(20);

        return view('dealer.imports.index', [
            'imports' => $imports, ...$this->layout($account, $tiers),
        ]);
    }

    public function template(Request $request, DealerContextService $context,
        DealerOrderImportWorkbook $workbook): BinaryFileResponse
    {
        $this->account($request, $context);
        Storage::disk('local')->makeDirectory('dealer-import-templates');
        $path = Storage::disk('local')->path('dealer-import-templates/'.Str::uuid().'.zip');
        $workbook->template($path);

        return response()->download($path, 'dealer_order_template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function store(Request $request, DealerContextService $context,
        DealerOrderImportService $imports): RedirectResponse
    {
        $account = $this->account($request, $context);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('dealer_order_import.max_file_kb')],
            'dealer_account_id' => ['prohibited'], 'tier_id' => ['prohibited'],
            'warehouse_id' => ['prohibited'], 'price' => ['prohibited'], 'total' => ['prohibited'],
        ]);
        $import = $this->runDomain(fn () => $imports->upload($request->user(), $account, $data['file']));

        return redirect()->route('dealer.web.imports.show', $import)->with('success', 'Đã tải file và kiểm tra bản xem trước.');
    }

    public function show(Request $request, DealerOrderImport $import, DealerContextService $context,
        DealerTierService $tiers, DealerOrderImportService $imports): View
    {
        $account = $this->account($request, $context);
        $imports->authorize($request->user(), $account, $import);
        $import->load(['rows', 'groups.order:id,order_code,order_status', 'uploader:id,name']);

        return view('dealer.imports.show', [
            'import' => $import, 'operationKey' => old('operation_key', (string) Str::uuid()),
            ...$this->layout($account, $tiers),
        ]);
    }

    public function revalidate(Request $request, DealerOrderImport $import, DealerContextService $context,
        DealerOrderImportService $imports): RedirectResponse
    {
        $account = $this->account($request, $context);
        $this->runDomain(fn () => $imports->revalidate($request->user(), $account, $import));

        return redirect()->route('dealer.web.imports.show', $import)->with('success', 'Đã kiểm tra lại giá, kho và số dư ví.');
    }

    public function confirm(Request $request, DealerOrderImport $import, DealerContextService $context,
        DealerOrderImportService $imports): RedirectResponse
    {
        $account = $this->account($request, $context);
        $data = $request->validate([
            'operation_key' => ['required', 'uuid'],
            'preview_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'dealer_account_id' => ['prohibited'], 'tier_id' => ['prohibited'],
            'warehouse_id' => ['prohibited'], 'price' => ['prohibited'], 'total' => ['prohibited'],
        ]);
        $this->runDomain(fn () => $imports->confirm($request->user(), $account, $import,
            $data['preview_fingerprint'], $data['operation_key']));

        return redirect()->route('dealer.web.imports.show', $import)->with('success', 'Đã xử lý các nhóm đơn trong file.');
    }

    private function account(Request $request, DealerContextService $context): DealerAccount
    {
        abort_unless($request->user()?->role === User::ROLE_CUSTOMER, 404);
        $account = $context->primaryAccount($request->user());
        abort_if($account === null, 403, 'Cần tư cách đại lý đang hoạt động.');

        return $account;
    }

    /** @return array<string, mixed> */
    private function layout(DealerAccount $account, DealerTierService $tiers): array
    {
        $account->load('wallet');

        return ['account' => $account, 'effectiveTier' => $tiers->resolve($account)['effective_tier']];
    }

    private function runDomain(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (HttpResponseException $exception) {
            if ($exception->getResponse()->getStatusCode() !== 409) {
                throw $exception;
            }
            $code = $exception->getResponse()->getData(true)['code'] ?? 'DEALER_IMPORT_CONFLICT';
            throw ValidationException::withMessages(['operation' => 'Không thể xử lý file ('.$code.'). Hãy kiểm tra và thử lại.']);
        }
    }
}
