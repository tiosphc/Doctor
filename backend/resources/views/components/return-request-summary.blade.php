@php
    $statusLabels = [
        'requested' => 'Chờ duyệt',
        'approved' => 'Đã duyệt, chờ nhận hàng',
        'rejected' => 'Đã từ chối',
        'pending' => 'Đã nhận, chờ kiểm tra',
        'completed' => 'Đã kiểm tra',
    ];
    $ineligibleMessages = [
        'ORDER_CANCELLED' => 'Đơn hàng đã hủy.',
        'ORDER_NOT_DELIVERED' => 'Có thể yêu cầu trả sau khi đơn đã giao đủ.',
        'RETURN_WINDOW_EXPIRED' => 'Đã hết thời hạn yêu cầu trả hàng.',
        'NOTHING_RETURNABLE' => 'Không còn sản phẩm có thể yêu cầu trả.',
    ];
    $createRoute = $returnChannel === 'retail' ? 'retail.web.orders.returns.create' : 'dealer.web.orders.returns.create';
    $showRoute = $returnChannel === 'retail' ? 'retail.web.orders.returns.show' : 'dealer.web.orders.returns.show';
    $hasWholeItem = collect($returnableItems)->contains(fn (array $item): bool => bccomp($item['returnable_quantity'], '1', 3) >= 0);
@endphp
<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-junie-navy">Trả hàng & hoàn tiền</h2>
            <p class="mt-2 text-sm text-slate-600">
                @if ($returnEligibility['return_eligible'])
                    Có thể gửi yêu cầu trả hàng trong {{ $returnEligibility['return_window_days'] }} ngày kể từ khi giao đủ.
                @else
                    {{ $ineligibleMessages[$returnEligibility['return_ineligible_reason']] ?? 'Đơn hàng chưa đủ điều kiện trả.' }}
                @endif
                @if ($returnEligibility['return_deadline'])
                    Hạn: {{ \Carbon\Carbon::parse($returnEligibility['return_deadline'])->format('d/m/Y H:i') }}.
                @endif
            </p>
        </div>
        @if ($returnEligibility['return_eligible'] && $hasWholeItem)
            <a href="{{ route($createRoute, $order->id) }}" class="inline-flex min-h-11 items-center rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Yêu cầu trả hàng</a>
        @endif
    </div>
    @if ($returnableItems !== [])
        <div class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[520px] text-left text-sm">
                <thead class="bg-slate-50"><tr><th class="px-3 py-2">Sản phẩm / SKU</th><th class="px-3 py-2 text-right">Đã giao</th><th class="px-3 py-2 text-right">Đang xử lý</th><th class="px-3 py-2 text-right">Còn được trả</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($returnableItems as $item)
                        <tr><td class="px-3 py-3">{{ $item['product_name'] }}<span class="block text-xs text-slate-500">{{ $item['sku'] }}</span></td><td class="px-3 py-3 text-right">{{ $item['fulfilled_quantity'] }}</td><td class="px-3 py-3 text-right">{{ $item['pending_quantity'] }}</td><td class="px-3 py-3 text-right font-semibold">{{ $item['returnable_quantity'] }} {{ $item['unit_code'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <div class="mt-5 border-t border-slate-200 pt-4">
        <h3 class="font-semibold text-junie-navy">Lịch sử yêu cầu</h3>
        @forelse ($returnRequests as $entry)
            <a href="{{ route($showRoute, [$order->id, $entry->id]) }}" class="mt-3 block rounded-xl border border-slate-200 p-3 text-sm hover:border-junie-navy">
                <span class="flex flex-wrap justify-between gap-2"><strong>{{ $entry->return_code }}</strong><span>{{ $statusLabels[$entry->status] ?? $entry->status }}</span></span>
                <span class="mt-1 block text-slate-600">{{ $entry->created_at?->format('d/m/Y H:i') }} · {{ $entry->items->map(fn ($item) => $item->salesOrderItem?->sku_snapshot.' × '.$item->quantity)->implode(', ') }}</span>
            </a>
        @empty
            <p class="mt-2 text-sm text-slate-500">Chưa có yêu cầu trả hàng.</p>
        @endforelse
        <x-pagination :paginator="$returnRequests" />
    </div>
</section>
