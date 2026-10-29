<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DealerAccount;
use App\Models\DealerOrderImport;
use App\Services\DealerContextService;
use App\Services\DealerOrderImportService;
use App\Services\DealerOrderImportWorkbook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DealerOrderImportController extends Controller
{
    public function template(Request $request, DealerAccount $dealer, DealerContextService $context,
        DealerOrderImportWorkbook $workbook): BinaryFileResponse
    {
        $context->resolve($request->user(), $dealer);
        Storage::disk('local')->makeDirectory('dealer-import-templates');
        $path = Storage::disk('local')->path('dealer-import-templates/'.Str::uuid().'.zip');
        $workbook->template($path);

        return response()->download($path, 'dealer_order_template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function index(Request $request, DealerAccount $dealer, DealerContextService $context): JsonResponse
    {
        $context->resolve($request->user(), $dealer);
        $page = DealerOrderImport::query()->where('dealer_account_id', $dealer->id)
            ->where('uploaded_by', $request->user()->id)->with('uploader:id,name')
            ->latest('id')->paginate(20);

        return response()->json($page);
    }

    public function store(Request $request, DealerAccount $dealer, DealerOrderImportService $service): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:'.config('dealer_order_import.max_file_kb')],
            'dealer_account_id' => ['prohibited'], 'tier_id' => ['prohibited'],
            'warehouse_id' => ['prohibited'], 'price' => ['prohibited'], 'total' => ['prohibited']]);
        $import = $service->upload($request->user(), $dealer, $data['file']);

        return response()->json(['data' => $this->detail($import)], 201);
    }

    public function show(Request $request, DealerAccount $dealer, DealerOrderImport $import,
        DealerOrderImportService $service): JsonResponse
    {
        $service->authorize($request->user(), $dealer, $import);

        return response()->json(['data' => $this->detail($import)]);
    }

    public function revalidate(Request $request, DealerAccount $dealer, DealerOrderImport $import,
        DealerOrderImportService $service): JsonResponse
    {
        return response()->json(['data' => $this->detail($service->revalidate($request->user(), $dealer, $import))]);
    }

    public function confirm(Request $request, DealerAccount $dealer, DealerOrderImport $import,
        DealerOrderImportService $service): JsonResponse
    {
        $data = $request->validate(['operation_key' => ['required', 'uuid'],
            'preview_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'dealer_account_id' => ['prohibited'], 'tier_id' => ['prohibited'],
            'warehouse_id' => ['prohibited'], 'price' => ['prohibited'], 'total' => ['prohibited']]);
        $result = $service->confirm($request->user(), $dealer, $import,
            $data['preview_fingerprint'], $data['operation_key']);

        return response()->json(['data' => $this->detail($result)]);
    }

    /** @return array<string, mixed> */
    private function detail(DealerOrderImport $import): array
    {
        $import->load(['rows', 'groups.order:id,order_code,order_status', 'uploader:id,name']);

        return [
            'id' => $import->id, 'dealer_account_id' => $import->dealer_account_id,
            'warehouse_id' => $import->warehouse_id,
            'original_filename' => $import->original_filename, 'file_hash' => $import->file_hash,
            'file_size' => $import->file_size, 'import_mode' => $import->import_mode,
            'status' => $import->status,
            'row_count' => $import->row_count, 'order_count' => $import->order_count,
            'valid_order_count' => $import->valid_order_count, 'invalid_order_count' => $import->invalid_order_count,
            'preview_fingerprint' => $import->preview_fingerprint, 'preview_summary' => $import->preview_summary,
            'uploaded_by' => $import->uploader?->name, 'created_at' => $import->created_at,
            'confirmed_at' => $import->confirmed_at, 'rows' => $import->rows->map(fn ($row): array => [
                'row' => $row->sheet_row_number, 'external_reference' => $row->external_reference,
                'sku' => $row->sku_input, 'quantity' => $row->quantity,
                'errors' => $row->validation_errors ?? [],
            ]),
            'groups' => $import->groups->map(fn ($group): array => [
                'id' => $group->id, 'external_reference' => $group->external_reference,
                'status' => $group->status, 'preview' => $group->preview,
                'error_code' => $group->error_code, 'sales_order_id' => $group->sales_order_id,
                'order_code' => $group->order?->order_code,
            ]),
        ];
    }
}
