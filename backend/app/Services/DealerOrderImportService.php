<?php

namespace App\Services;

use App\Models\DealerAccount;
use App\Models\DealerOrderImport;
use App\Models\DealerOrderImportGroup;
use App\Models\InventoryBalance;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\User;
use App\Support\Sku;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DealerOrderImportService
{
    private const RECIPIENT_MAP = [
        'Customer Name' => 'recipient_name', 'Phone' => 'recipient_phone',
        'Email' => 'recipient_email', 'Street' => 'shipping_address_line1',
        'City' => 'shipping_city', 'State' => 'shipping_province',
        'Country' => 'shipping_country', 'Zip Code' => 'shipping_postal_code',
    ];

    private const REQUIRED = ['SKU', 'Customer Name', 'Phone', 'Street', 'Quantity'];

    public function __construct(
        private readonly DealerOrderImportWorkbook $workbook,
        private readonly DealerQuickOrderService $orders,
        private readonly DealerContextService $context,
        private readonly AuditLogger $audit,
        private readonly DealerWalletService $wallets,
        private readonly SalesPromotionService $promotions,
    ) {}

    public function upload(User $user, DealerAccount $account, UploadedFile $file): DealerOrderImport
    {
        $this->context->resolve($user, $account);
        if (strtolower($file->getClientOriginalExtension()) !== 'xlsx'
            || ! in_array($file->getMimeType(), ['application/zip',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true)) {
            $this->fail('INVALID_XLSX');
        }
        $size = $file->getSize();
        if ($size === false || $size > config('dealer_order_import.max_file_kb') * 1024) {
            $this->fail('XLSX_TOO_LARGE');
        }
        $hash = hash_file('sha256', $file->getRealPath());
        $previous = DealerOrderImport::query()->where('dealer_account_id', $account->id)
            ->where('file_hash', $hash)->where('status', 'completed')->first();
        if ($previous !== null) {
            $this->fail('IMPORT_FILE_ALREADY_COMPLETED', ['import_id' => $previous->id]);
        }
        $path = $file->storeAs('dealer-order-import-tmp', Str::uuid().'.zip', 'local');
        try {
            $parsed = $this->workbook->parse(Storage::disk('local')->path($path));
        } finally {
            Storage::disk('local')->delete($path);
        }
        if ($parsed === []) {
            $this->fail('IMPORT_EMPTY');
        }
        $filename = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255);
        $import = DB::transaction(function () use ($user, $account, $filename, $hash, $size, $parsed): DealerOrderImport {
            $import = DealerOrderImport::create([
                'dealer_account_id' => $account->id, 'uploaded_by' => $user->id,
                'original_filename' => $filename, 'file_hash' => $hash, 'file_size' => $size,
                'row_count' => count($parsed),
            ]);
            $groups = [];
            foreach ($parsed as $source) {
                $values = $source['values'];
                $sku = Sku::normalize($values['SKU']);
                $promotionCode = $this->promotions->normalize((string) ($values['Voucher Code'] ?? ''));
                $recipient = [];
                foreach (self::RECIPIENT_MAP as $column => $field) {
                    $recipient[$field] = $this->normalizeWhitespace($values[$column] ?? '');
                }
                $recipient['shipping_province_code'] = trim($values['Province Code'] ?? '');
                $recipient['shipping_ward_code'] = trim($values['Ward Code'] ?? '');
                $recipient['shipping_ward'] = trim($values['City'] ?? '');
                $recipient['recipient_phone'] = preg_replace('/[^0-9]+/', '', $recipient['recipient_phone']);
                $recipient['recipient_email'] = mb_strtolower($recipient['recipient_email']);
                $recipient['delivery_note'] = null;
                $errors = [];
                foreach (self::REQUIRED as $column) {
                    if (trim($values[$column] ?? '') === '') {
                        $errors[] = ['field' => $column, 'code' => 'REQUIRED_FIELD'];
                    }
                }
                if (($recipient['shipping_province_code'] ?: $recipient['shipping_province']) === '') {
                    $errors[] = ['field' => 'Province Code', 'code' => 'REQUIRED_FIELD'];
                }
                if (($recipient['shipping_ward_code'] ?: $recipient['shipping_ward']) === '') {
                    $errors[] = ['field' => 'Ward Code', 'code' => 'REQUIRED_FIELD'];
                }
                if (trim($values['Phone']) !== '' && $recipient['recipient_phone'] === '') {
                    $errors[] = ['field' => 'Phone', 'code' => 'INVALID_PHONE', 'value' => $values['Phone']];
                }
                if (mb_strlen($promotionCode) > 80) {
                    $errors[] = ['field' => 'Voucher Code', 'code' => 'INVALID_PROMOTION_CODE'];
                }
                if ($recipient['recipient_email'] !== '' && filter_var($recipient['recipient_email'], FILTER_VALIDATE_EMAIL) === false) {
                    $errors[] = ['field' => 'Email', 'code' => 'INVALID_EMAIL', 'value' => $values['Email']];
                }
                $quantity = trim($values['Quantity']);
                if (preg_match('/^[1-9][0-9]{0,14}$/', $quantity) !== 1) {
                    $errors[] = ['field' => 'Quantity', 'code' => 'INVALID_QUANTITY', 'value' => $values['Quantity']];
                }
                $externalReference = $this->normalizeWhitespace($values['Order Code'] ?? '');
                if ($externalReference !== '' && (mb_strlen($externalReference) > 80
                    || preg_match('/^[\pL\pN_-]+$/u', $externalReference) !== 1)) {
                    $errors[] = ['field' => 'Order Code', 'code' => 'INVALID_ORDER_CODE'];
                }
                $groupIdentity = $this->recipientGroupKey($recipient);
                if ($recipient['recipient_phone'] === '' || $recipient['shipping_address_line1'] === ''
                    || ($recipient['shipping_province_code'] === '' && $recipient['shipping_province'] === '')) {
                    $groupIdentity = 'INVALID-ROW-'.$source['row'];
                }
                if (! isset($groups[$groupIdentity])) {
                    $groups[$groupIdentity] = [
                        'reference' => $externalReference !== '' ? $externalReference
                            : 'GROUP-'.str_pad((string) (count($groups) + 1), 3, '0', STR_PAD_LEFT),
                        'normalized_reference' => 'AUTO-'.strtoupper(substr(hash('sha256',
                            $account->id.'|'.$hash.'|'.$groupIdentity), 0, 40)),
                    ];
                }
                $groupKey = $groups[$groupIdentity]['normalized_reference'];
                $import->rows()->create([
                    'sheet_row_number' => $source['row'], 'external_reference' => $externalReference ?: null,
                    'external_reference_normalized' => $groupKey, 'sku_input' => $sku,
                    'quantity' => $quantity, 'promotion_code' => $promotionCode ?: null,
                    'recipient' => $recipient, 'validation_errors' => $errors,
                ]);
            }
            if (count($groups) > config('dealer_order_import.max_orders')) {
                $this->fail('IMPORT_ORDER_LIMIT');
            }
            foreach ($groups as $group) {
                $import->groups()->create(['external_reference' => $group['reference'],
                    'external_reference_normalized' => $group['normalized_reference']]);
            }
            $import->update(['order_count' => count($groups)]);
            $this->audit->log(AuditLogger::ACTION_CREATE, AuditLogger::MODULE_DEALER_ORDER_IMPORT,
                $import, 'Dealer order workbook uploaded', metadata: [
                    'dealer_account_id' => $account->id, 'row_count' => count($parsed),
                    'order_count' => count($groups), 'file_hash' => $hash,
                ]);

            return $import;
        }, 3);

        return $this->revalidate($user, $account, $import);
    }

    public function revalidate(User $user, DealerAccount $account, DealerOrderImport $import): DealerOrderImport
    {
        $this->authorize($user, $account, $import);
        $import->load(['rows', 'groups']);
        $variants = ProductVariant::query()->with('unit')
            ->whereIn('sku', $import->rows->pluck('sku_input')->unique()->all())
            ->get()->keyBy(fn (ProductVariant $variant): string => Sku::normalize($variant->sku));
        $groupPreviews = [];
        $demand = [];
        $total = '0.00';
        foreach ($import->groups as $group) {
            if ($group->sales_order_id !== null) {
                $groupPreviews[$group->id] = $group->preview;

                continue;
            }
            $rows = $import->rows->where('external_reference_normalized', $group->external_reference_normalized);
            $errors = [];
            $recipient = $rows->first()->recipient;
            $promotionCode = $rows->first()->promotion_code;
            $notes = [];
            $linesByVariant = [];
            foreach ($rows as $row) {
                foreach ($row->validation_errors ?? [] as $error) {
                    $errors[] = ['row' => $row->sheet_row_number, ...$error];
                }
                $currentRecipient = $row->recipient;
                $note = (string) ($currentRecipient['delivery_note'] ?? '');
                unset($currentRecipient['delivery_note']);
                $baseRecipient = $recipient;
                unset($baseRecipient['delivery_note']);
                if ($this->recipientComparisonKey($currentRecipient) !== $this->recipientComparisonKey($baseRecipient)) {
                    $errors[] = ['row' => $row->sheet_row_number, 'field' => 'Recipient',
                        'code' => 'ORDER_GROUP_RECIPIENT_MISMATCH'];
                }
                if ($row->promotion_code !== $promotionCode) {
                    $errors[] = ['row' => $row->sheet_row_number, 'field' => 'Voucher Code',
                        'code' => 'ORDER_GROUP_PROMOTION_MISMATCH'];
                }
                if ($note !== '') {
                    $notes[$note] = true;
                }
                $variant = $variants->get($row->sku_input);
                if ($variant === null) {
                    $errors[] = ['row' => $row->sheet_row_number, 'field' => 'SKU', 'code' => 'SKU_NOT_FOUND', 'value' => $row->sku_input];
                } elseif ($row->quantity !== null && preg_match('/^[1-9][0-9]{0,14}$/', $row->quantity) !== 1) {
                    $errors[] = ['row' => $row->sheet_row_number, 'field' => 'Quantity', 'code' => 'INVALID_QUANTITY', 'value' => $row->quantity];
                }
                if ($variant !== null) {
                    if ($row->product_variant_id !== $variant->id) {
                        $row->update(['product_variant_id' => $variant->id]);
                    }
                    if (preg_match('/^[1-9][0-9]{0,14}$/', (string) $row->quantity) === 1) {
                        $quantity = bcadd($linesByVariant[$variant->id]['quantity'] ?? '0', $row->quantity, 0);
                        if (preg_match('/^[1-9][0-9]{0,14}$/', $quantity) !== 1) {
                            $errors[] = ['row' => $row->sheet_row_number, 'field' => 'Quantity',
                                'code' => 'INVALID_QUANTITY'];
                        } else {
                            $linesByVariant[$variant->id] = ['product_variant_id' => $variant->id,
                                'quantity' => $quantity,
                                'sheet_row_number' => $linesByVariant[$variant->id]['sheet_row_number'] ?? $row->sheet_row_number];
                        }
                    }
                }
            }
            $lines = array_values($linesByVariant);
            if (count($notes) > 1) {
                $errors[] = ['row' => $rows->first()->sheet_row_number, 'field' => 'Note',
                    'code' => 'ORDER_GROUP_NOTE_MISMATCH'];
            }
            if (count($lines) > config('dealer_order_import.max_lines_per_order')) {
                $errors[] = ['row' => $rows->first()->sheet_row_number, 'field' => 'SKU', 'code' => 'IMPORT_LINE_LIMIT'];
            }
            $recipient['delivery_note'] = array_key_first($notes) ?? null;
            $review = null;
            if ($errors === []) {
                try {
                    $review = $this->orders->review($user, $account, array_map(
                        fn (array $line): array => ['product_variant_id' => $line['product_variant_id'],
                            'quantity' => $line['quantity']], $lines), $recipient);
                    $recipient = $review['recipient_defaults'];
                    $warehouseId = $review['warehouse']['id'];
                    foreach ($review['items'] as $item) {
                        foreach ($item['errors'] as $code) {
                            $errors[] = ['row' => collect($lines)->firstWhere('product_variant_id', $item['product_variant_id'])['sheet_row_number'],
                                'field' => 'SKU', 'code' => $code];
                        }
                        $demand[$warehouseId][$item['product_variant_id']] = bcadd($demand[$warehouseId][$item['product_variant_id']] ?? '0.000', $item['quantity'], 3);
                    }
                    if ($review['gift_item'] !== null) {
                        $gift = $review['gift_item'];
                        $demand[$warehouseId][$gift['product_variant_id']] = bcadd($demand[$warehouseId][$gift['product_variant_id']] ?? '0.000', $gift['quantity'], 3);
                    }
                    $total = bcadd($total, $review['grand_total'], 2);
                } catch (HttpResponseException $exception) {
                    $errors[] = ['row' => $rows->first()->sheet_row_number, 'field' => 'Order',
                        'code' => $exception->getResponse()->getData(true)['code'] ?? 'DEALER_ORDER_INVALID'];
                }
            }
            if ($group->external_reference_normalized !== '' && SalesOrder::query()
                ->where('dealer_account_id', $account->id)
                ->where('external_reference_normalized', $group->external_reference_normalized)->exists()) {
                $errors[] = ['row' => $rows->first()->sheet_row_number, 'field' => 'Order',
                    'code' => 'EXTERNAL_ORDER_REF_ALREADY_USED'];
            }
            $groupPreviews[$group->id] = ['external_reference' => $group->external_reference,
                'promotion_code' => $promotionCode,
                'recipient' => $recipient, 'items' => $review['items'] ?? [],
                'gift_item' => $review['gift_item'] ?? null,
                'warehouse' => $review['warehouse'] ?? null,
                'preferred_warehouse' => $review['preferred_warehouse'] ?? null,
                'fallback_used' => $review['fallback_used'] ?? false,
                'effective_tier' => $review['effective_tier'] ?? null,
                'subtotal' => $review['subtotal'] ?? '0.00',
                'discount_total' => $review['discount_total'] ?? '0.00',
                'grand_total' => $review['grand_total'] ?? '0.00', 'errors' => $errors,
                'review_fingerprint' => $review['review_fingerprint'] ?? null];
        }
        foreach ($demand as $warehouseId => $warehouseDemand) {
            $balances = InventoryBalance::query()->where('warehouse_id', $warehouseId)
                ->whereIn('product_variant_id', array_keys($warehouseDemand))->get()->keyBy('product_variant_id');
            foreach ($warehouseDemand as $variantId => $quantity) {
                if (bccomp($quantity, $balances->get($variantId)?->available_quantity ?? '0.000', 3) > 0) {
                    foreach ($groupPreviews as &$preview) {
                        if (($preview['warehouse']['id'] ?? null) === $warehouseId
                            && (collect($preview['items'])->contains('product_variant_id', $variantId)
                            || ($preview['gift_item']['product_variant_id'] ?? null) === $variantId)) {
                            $preview['errors'][] = ['row' => null, 'field' => 'Quantity',
                                'code' => 'IMPORT_BATCH_INSUFFICIENT_STOCK'];
                        }
                    }
                    unset($preview);
                }
            }
        }
        $walletSummary = $this->wallets->summary($account);
        $walletSufficient = bccomp((string) $walletSummary['available_balance'], $total, 2) >= 0;
        if (! $walletSufficient) {
            foreach ($groupPreviews as &$preview) {
                if (($preview['errors'] ?? []) === [] && ($preview['grand_total'] ?? '0.00') !== '0.00') {
                    $preview['errors'][] = ['row' => null, 'field' => 'Wallet', 'code' => 'DEALER_WALLET_INSUFFICIENT_BALANCE'];
                }
            }
            unset($preview);
        }
        $valid = 0;
        $fingerprintParts = [];
        foreach ($import->groups as $group) {
            $preview = $groupPreviews[$group->id];
            if ($group->sales_order_id === null) {
                $ready = $preview['errors'] === [];
                $import->groups()->whereKey($group->id)->whereNull('sales_order_id')->update([
                    'status' => $ready ? 'preview_ready' : 'invalid', 'preview' => json_encode($preview, JSON_THROW_ON_ERROR),
                    'review_fingerprint' => $preview['review_fingerprint'], 'error_code' => null,
                ]);
                $valid += (int) $ready;
            } else {
                $valid++;
            }
            $fingerprintParts[] = [$group->external_reference_normalized, $preview['recipient'] ?? null,
                $preview['review_fingerprint'] ?? null, $preview['errors'] ?? [], $group->sales_order_id];
        }
        $fingerprint = hash('sha256', json_encode([$account->id, $fingerprintParts], JSON_THROW_ON_ERROR));
        $import->update(['valid_order_count' => $valid, 'invalid_order_count' => $import->order_count - $valid,
            'preview_fingerprint' => $fingerprint, 'preview_summary' => [
                'estimated_total' => $total, 'warehouse_id' => null,
                'wallet_balance' => $walletSummary['available_balance'], 'wallet_sufficient' => $walletSufficient,
            ], 'status' => $import->groups->contains(fn ($group): bool => $group->sales_order_id !== null)
                ? $import->status : ($valid === $import->order_count ? 'preview_ready' : 'invalid')]);

        return $import->refresh()->load(['rows', 'groups', 'uploader:id,name']);
    }

    public function confirm(User $user, DealerAccount $account, DealerOrderImport $import, string $fingerprint, string $operationKey): DealerOrderImport
    {
        $this->authorize($user, $account, $import);
        $payloadFingerprint = hash('sha256', json_encode([$account->id, $import->id, $fingerprint], JSON_THROW_ON_ERROR));
        DB::transaction(function () use ($import, $operationKey, $payloadFingerprint, $fingerprint): void {
            $locked = DealerOrderImport::query()->lockForUpdate()->findOrFail($import->id);
            if ($locked->confirm_operation_key === $operationKey
                && $locked->confirm_payload_fingerprint !== $payloadFingerprint) {
                $this->fail('OPERATION_KEY_CONFLICT');
            }
            if ($fingerprint !== $locked->preview_fingerprint) {
                $this->fail('DEALER_IMPORT_CHANGED');
            }
            $locked->update(['confirm_operation_key' => $operationKey,
                'confirm_payload_fingerprint' => $payloadFingerprint]);
        }, 3);
        $import->refresh();
        $before = $import->groups()->whereNull('sales_order_id')->get()->keyBy('id');
        if ($before->isEmpty()) {
            return $import->refresh()->load(['rows', 'groups', 'uploader:id,name']);
        }
        $fresh = $this->revalidate($user, $account, $import);
        if ($fresh->preview_fingerprint !== $fingerprint || $fresh->invalid_order_count > 0) {
            $this->fail('DEALER_IMPORT_CHANGED');
        }
        $this->audit->log(AuditLogger::ACTION_CONFIRM, AuditLogger::MODULE_DEALER_ORDER_IMPORT,
            $import, 'Dealer order import confirmation started', metadata: [
                'dealer_account_id' => $account->id, 'order_count' => $import->order_count,
            ]);
        foreach ($before as $group) {
            try {
                DB::transaction(function () use ($user, $account, $import, $group): void {
                    $locked = DealerOrderImportGroup::query()->lockForUpdate()->findOrFail($group->id);
                    if ($locked->sales_order_id !== null) {
                        return;
                    }
                    $preview = $locked->preview;
                    $recipient = $preview['recipient'];
                    $items = array_map(fn (array $item): array => [
                        'product_variant_id' => $item['product_variant_id'],
                        'quantity' => $item['quantity'],
                    ], $preview['items']);
                    $data = [...$recipient, 'items' => $items,
                        'operation_key' => $this->groupKey($import->id, $locked->external_reference_normalized),
                        'review_fingerprint' => $locked->review_fingerprint,
                        'order_source' => 'dealer_excel',
                        'external_reference' => $import->rows()
                            ->where('external_reference_normalized', $locked->external_reference_normalized)
                            ->whereNotNull('external_reference')->exists()
                            ? $locked->external_reference : null,
                        'external_reference_normalized' => $locked->external_reference_normalized];
                    $order = $this->orders->submit($user, $account, $data);
                    $locked->update(['status' => 'created', 'sales_order_id' => $order->id, 'error_code' => null]);
                }, 3);
            } catch (HttpResponseException $exception) {
                $group->update(['status' => 'failed',
                    'error_code' => $exception->getResponse()->getData(true)['code'] ?? 'ORDER_SUBMISSION_FAILED']);
            } catch (QueryException $exception) {
                if (($exception->errorInfo[1] ?? null) !== 1062) {
                    throw $exception;
                }
                $group->update(['status' => 'failed', 'error_code' => 'EXTERNAL_ORDER_REF_ALREADY_USED']);
            }
        }
        $created = $import->groups()->whereNotNull('sales_order_id')->count();
        $import->update(['status' => $created === $import->order_count ? 'completed'
            : ($created > 0 ? 'partially_completed' : 'failed'),
            'confirmed_by' => $user->id, 'confirmed_at' => now()]);
        $this->audit->log(AuditLogger::ACTION_COMPLETE, AuditLogger::MODULE_DEALER_ORDER_IMPORT,
            $import, 'Dealer order import finished', metadata: [
                'dealer_account_id' => $account->id, 'status' => $import->status,
                'created_order_count' => $created,
            ]);

        return $import->refresh()->load(['rows', 'groups', 'uploader:id,name']);
    }

    public function authorize(User $user, DealerAccount $account, DealerOrderImport $import): void
    {
        $this->context->resolve($user, $account);
        abort_unless($import->dealer_account_id === $account->id && $import->uploaded_by === $user->id, 404);
    }

    private function normalizeWhitespace(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }

    /** @param array<string, mixed> $recipient */
    private function recipientGroupKey(array $recipient): string
    {
        $address = array_map(fn (string $field): string => mb_strtoupper($this->normalizeWhitespace(
            (string) ($recipient[$field] ?? ''),
        )), ['shipping_address_line1', 'shipping_address_line2', 'shipping_city', 'shipping_district',
            'shipping_province', 'shipping_country', 'shipping_postal_code',
            'shipping_province_code', 'shipping_ward_code']);

        return hash('sha256', json_encode([
            mb_strtoupper($this->normalizeWhitespace((string) ($recipient['recipient_name'] ?? ''))),
            preg_replace('/[^0-9]+/', '', (string) ($recipient['recipient_phone'] ?? '')),
            ...$address,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $recipient */
    private function recipientComparisonKey(array $recipient): string
    {
        return hash('sha256', json_encode([
            $this->recipientGroupKey($recipient),
            mb_strtoupper($this->normalizeWhitespace((string) ($recipient['recipient_name'] ?? ''))),
            mb_strtolower($this->normalizeWhitespace((string) ($recipient['recipient_email'] ?? ''))),
            mb_strtoupper($this->normalizeWhitespace((string) ($recipient['shipping_address_line2'] ?? ''))),
            (string) ($recipient['shipping_province_code'] ?? ''),
            (string) ($recipient['shipping_ward_code'] ?? ''),
        ], JSON_THROW_ON_ERROR));
    }

    private function groupKey(int $importId, string $reference): string
    {
        $hash = hash('sha256', 'dealer-import:'.$importId.':'.$reference);

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-5'.substr($hash, 13, 3)
            .'-8'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
    }

    /** @param array<string, mixed> $details */
    private function fail(string $code, array $details = []): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code, ...$details], 409));
    }
}
