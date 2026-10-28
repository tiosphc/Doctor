@extends('layouts.admin')

@section('title', $editingOrder ? 'Sửa đơn '.$editingOrder->order_code : 'Tạo đơn Retail')
@section('page-title', $editingOrder ? 'Sửa đơn '.$editingOrder->order_code : 'Tạo đơn Retail')

@section('content')
<div class="mx-auto max-w-5xl space-y-6" x-data="salesOrderForm()">
    <a href="{{ route('admin.web.sales.orders.index') }}" class="text-sm font-semibold text-junie-navy hover:underline">← Danh sách đơn hàng</a>
    <header><h1 class="text-2xl font-semibold text-junie-navy">{{ $editingOrder ? 'Sửa đơn Retail nháp '.$editingOrder->order_code : 'Tạo đơn Retail nháp' }}</h1><p class="mt-2 text-sm text-slate-600">Giá và tồn kho được lấy từ máy chủ khi xem trước. Đơn nháp chưa giữ hàng hoặc ghi nhận thanh toán. Giá được tính lại khi lưu.</p></header>

    @if ($editingOrder)
        <section class="rounded-2xl border border-slate-200 bg-white p-5 text-sm shadow-sm"><p class="font-semibold text-junie-navy">Giá đã lưu trong bản nháp</p><p class="mt-2">Tổng hiện tại: <x-formatted-number :value="$editingOrder->grand_total" currency /> · {{ $editingOrder->items->count() }} SKU</p><p class="mt-1 text-slate-600">Giá có thể đổi khi lưu do bảng giá Retail hiện hành. Xem trước chỉ để tham khảo và không ghi nhận tồn kho.</p></section>
    @endif

    <form action="{{ $editingOrder ? route('admin.web.sales.orders.draft.update', $editingOrder) : route('admin.web.sales.orders.store') }}" method="POST" class="space-y-6" @submit="submitting = true">
        @csrf
        <input type="hidden" name="operation_key" value="{{ $operationKey }}">
        <input type="hidden" name="sales_channel" value="retail">
        <input type="hidden" name="currency" value="VND">
        @error('operation')<p class="text-sm text-red-700">{{ $message }}</p>@enderror

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-junie-navy">Khách hàng và kho</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="relative">
                    <label for="buyer-search" class="block text-sm font-semibold">Khách hàng *</label>
                    <input id="buyer-search" type="search" x-model="buyerSearch" @input.debounce.300ms="loadBuyers" @focus="loadBuyers" autocomplete="off" placeholder="Tìm tên, email hoặc điện thoại" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm">
                    <input type="hidden" name="buyer_user_id" x-model="buyerId">
                    <p x-show="buyerId" class="mt-1 text-xs text-emerald-700" x-text="'Đã chọn: ' + (buyer?.name || '')"></p>
                    <div x-show="buyerResults.length" @click.outside="buyerResults = []" class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-xl border border-slate-200 bg-white shadow-lg">
                        <template x-for="buyerOption in buyerResults" :key="buyerOption.id"><button type="button" @click="selectBuyer(buyerOption)" class="block min-h-11 w-full border-b border-slate-100 px-3 py-2 text-left text-sm hover:bg-slate-50"><span class="font-semibold" x-text="buyerOption.name"></span><span class="block text-xs text-slate-500" x-text="buyerOption.email + ' · ' + (buyerOption.phone || '')"></span></button></template>
                    </div>
                    @error('buyer_user_id')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <div><label for="warehouse_id" class="block text-sm font-semibold">Kho xuất *</label><select id="warehouse_id" name="warehouse_id" required @change="previewFresh = false" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"><option value="">Chọn kho</option>@foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected(old('warehouse_id', $editingOrder?->warehouse_id ?? $warehouses->firstWhere('is_default_sales', true)?->id) == $warehouse->id)>{{ $warehouse->name }} ({{ $warehouse->code }})</option>@endforeach</select>@error('warehouse_id')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-semibold text-junie-navy">Sản phẩm</h2><span class="text-xs text-slate-500">Tối đa 50 SKU</span></div>
            <div class="relative mt-4"><label for="sku-search" class="block text-sm font-semibold">Tìm SKU Retail</label><input id="sku-search" type="search" x-model="skuSearch" @input.debounce.300ms="loadSkus" @focus="loadSkus" autocomplete="off" placeholder="SKU, sản phẩm hoặc biến thể" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"><div x-show="skuResults.length" @click.outside="skuResults = []" class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-xl border border-slate-200 bg-white shadow-lg"><template x-for="skuOption in skuResults" :key="skuOption.sku"><button type="button" @click="addSku(skuOption)" class="block min-h-11 w-full border-b border-slate-100 px-3 py-2 text-left text-sm hover:bg-slate-50" x-text="skuOption.label"></button></template></div></div>
            <p x-show="items.length === 0" class="mt-4 text-sm text-slate-500">Chưa chọn sản phẩm.</p>
            <div class="mt-4 space-y-3"><template x-for="(item, index) in items" :key="item.sku"><div class="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 sm:grid-cols-[minmax(0,1fr)_8rem_auto] sm:items-end"><div class="min-w-0"><p class="break-words text-sm font-semibold" x-text="item.label"></p><p class="break-all font-mono text-xs text-slate-500" x-text="item.sku"></p><input type="hidden" :name="'items['+index+'][sku]'" :value="item.sku"></div><label class="text-sm font-semibold">Số lượng *<input type="number" min="1" step="1" required :name="'items['+index+'][quantity]'" x-model="item.quantity" @input="previewFresh = false" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label><button type="button" @click="items.splice(index, 1); previewFresh = false" class="min-h-11 rounded-xl border border-red-200 px-3 text-sm font-semibold text-red-700">Xóa</button></div></template></div>
            @error('items')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
            @foreach ($errors->get('items.*') as $messages) @foreach ($messages as $message)<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@endforeach @endforeach
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-junie-navy">Người nhận và địa chỉ</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                @foreach (['recipient_name' => 'Tên người nhận *', 'recipient_phone' => 'Điện thoại *', 'recipient_email' => 'Email', 'shipping_address_line1' => 'Địa chỉ *', 'shipping_address_line2' => 'Địa chỉ bổ sung', 'shipping_district' => 'Quận / huyện', 'shipping_city' => 'Thành phố *', 'shipping_province' => 'Tỉnh / thành *', 'shipping_country' => 'Quốc gia *', 'shipping_postal_code' => 'Mã bưu chính'] as $field => $label)
                    <label class="block"><span class="text-sm font-semibold">{{ $label }}</span><input name="{{ $field }}" id="{{ $field }}" value="{{ old($field, $editingOrder?->{$field} ?? ($field === 'shipping_country' ? 'VN' : '')) }}" @if (str_ends_with($label, '*')) required @endif class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm">@error($field)<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror</label>
                @endforeach
                <label class="block"><span class="text-sm font-semibold">Phương thức thanh toán</span><select name="payment_method" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"><option value="">Chưa chọn</option><option value="cod" @selected(old('payment_method', $editingOrder?->payment_method) === 'cod')>COD</option><option value="bank_transfer" @selected(old('payment_method', $editingOrder?->payment_method) === 'bank_transfer')>Chuyển khoản</option></select>@error('payment_method')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror</label>
                <label class="block sm:col-span-2"><span class="text-sm font-semibold">Ghi chú giao hàng</span><textarea name="delivery_note" rows="3" class="mt-2 w-full rounded-xl border border-slate-300 p-3 text-sm">{{ old('delivery_note', $editingOrder?->delivery_note) }}</textarea>@error('delivery_note')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror</label>
            </div>
        </section>

        @if ($preview)
            <section x-show="previewFresh" class="rounded-2xl border border-blue-200 bg-blue-50 p-5"><h2 class="text-lg font-semibold text-junie-navy">Giá xem trước từ máy chủ</h2><div class="mt-3 overflow-x-auto"><table class="w-full min-w-[580px] text-sm"><thead><tr class="border-b border-blue-200 text-left"><th class="py-2">SKU</th><th>Số lượng</th><th>Đơn giá</th><th>Thành tiền</th><th>Tồn khả dụng</th></tr></thead><tbody>@foreach ($preview['items'] as $item)<tr class="border-b border-blue-100"><td class="py-2">{{ $item['sku'] }} · {{ $item['product_name'] }}</td><td>{{ $item['quantity'] }}</td><td><x-formatted-number :value="$item['unit_price']" currency /></td><td><x-formatted-number :value="$item['line_total']" currency /></td><td class="{{ $item['insufficient_stock'] ? 'font-semibold text-red-700' : '' }}">{{ $item['available_quantity'] }}{{ $item['insufficient_stock'] ? ' · Thiếu hàng' : '' }}</td></tr>@endforeach</tbody></table></div><p class="mt-3 text-right text-lg font-semibold">Tổng: <x-formatted-number :value="$preview['grand_total']" currency /></p><p class="mt-1 text-right text-xs text-slate-600">Giảm giá: <x-formatted-number :value="$preview['discount_total']" currency /> · Giá được tính lại khi lưu.</p></section>
            <p x-show="!previewFresh" class="text-sm text-amber-700">Sản phẩm hoặc kho đã thay đổi. Hãy xem trước lại để cập nhật giá và tồn kho.</p>
        @endif
        <div class="flex flex-wrap gap-3"><button type="submit" formaction="{{ $editingOrder ? route('admin.web.sales.orders.draft.preview', $editingOrder) : route('admin.web.sales.orders.preview') }}" formnovalidate :disabled="submitting || items.length === 0" class="min-h-11 rounded-xl border border-junie-navy px-5 text-sm font-semibold text-junie-navy disabled:opacity-50">Xem trước giá</button><button type="submit" :disabled="submitting || items.length === 0" class="min-h-11 rounded-xl bg-junie-navy px-5 text-sm font-semibold text-white disabled:opacity-50">{{ $editingOrder ? 'Lưu thay đổi' : 'Lưu đơn nháp' }}</button></div>
    </form>
</div>
<script>
function salesOrderForm() {
    return {
        optionsUrl: @js(route('admin.web.sales.orders.options')),
        buyer: @js($selectedBuyer), buyerId: @js((string) old('buyer_user_id', $editingOrder?->buyer_user_id ?? '')),
        buyerSearch: @js($selectedBuyer?->name ?? ''), buyerResults: [], skuSearch: '', skuResults: [],
        items: @js($initialItems), previewFresh: @js($preview !== null), submitting: false,
        async search(type, search) {
            const response = await fetch(this.optionsUrl + '?' + new URLSearchParams({type, search}), {headers: {'Accept': 'application/json'}});
            return response.ok ? (await response.json()).data : [];
        },
        async loadBuyers() {
            const search = this.buyerSearch;
            if (this.buyer && search !== this.buyer.name) this.buyerId = '';
            this.buyerResults = await this.search('buyer', search);
            if (this.buyerSearch !== search) this.buyerResults = [];
        },
        selectBuyer(option) {
            this.buyer = option; this.buyerId = option.id; this.buyerSearch = option.name; this.buyerResults = [];
            if (!document.getElementById('recipient_name').value) document.getElementById('recipient_name').value = option.name;
            if (!document.getElementById('recipient_phone').value) document.getElementById('recipient_phone').value = option.phone || '';
            if (!document.getElementById('recipient_email').value) document.getElementById('recipient_email').value = option.email || '';
        },
        async loadSkus() {
            const search = this.skuSearch;
            this.skuResults = await this.search('sku', search);
            if (this.skuSearch !== search) this.skuResults = [];
        },
        addSku(option) {
            if (this.items.length < 50 && !this.items.some(item => item.sku === option.sku)) {
                this.items.push({sku: option.sku, label: option.label, quantity: '1'});
                this.previewFresh = false;
            }
            this.skuSearch = ''; this.skuResults = [];
        },
    };
}
</script>
@endsection
