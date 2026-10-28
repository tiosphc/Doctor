@extends('layouts.admin')

@section('title', $context === 'retail' ? 'Bảng giá Retail' : 'Bảng giá Đại lý')
@section('page-title', $context === 'retail' ? 'Bảng giá Retail' : 'Bảng giá Đại lý')

@section('content')
<div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Sản phẩm / Giá</p>
            <h1 class="mt-2 text-3xl font-semibold text-junie-navy">{{ $context === 'retail' ? 'Bảng giá Retail' : 'Bảng giá Đại lý' }}</h1>
            <p class="mt-2 text-sm text-slate-600">Giá gốc theo từng SKU{{ $context === 'dealer' ? ' và Tier' : '' }}. Mỗi trang phân theo tối đa 20 SKU.</p>
        </div>
        <div class="flex flex-wrap gap-2 text-sm font-semibold">
            <a href="{{ route('admin.web.prices.retail.index', array_filter(['product_id' => $filters['product_id'] ?? null])) }}" class="rounded-lg px-3 py-2 {{ $context === 'retail' ? 'bg-junie-navy text-white' : 'border border-slate-300 text-junie-navy' }}">Retail</a>
            <a href="{{ route('admin.web.prices.dealer.index', array_filter(['product_id' => $filters['product_id'] ?? null])) }}" class="rounded-lg px-3 py-2 {{ $context === 'dealer' ? 'bg-junie-navy text-white' : 'border border-slate-300 text-junie-navy' }}">Đại lý</a>
        </div>
    </header>

    <form method="GET" action="{{ route('admin.web.prices.'.$context.'.index') }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-2 xl:grid-cols-4">
        @if(!empty($filters['product_id']))<input type="hidden" name="product_id" value="{{ $filters['product_id'] }}">@endif
        <div class="{{ $context === 'dealer' ? 'xl:col-span-1' : 'xl:col-span-2' }}"><label for="price-search" class="block text-sm font-semibold text-slate-700">Sản phẩm hoặc SKU</label><input id="price-search" name="search" maxlength="100" value="{{ $filters['search'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3" placeholder="Tên, mã, SKU, biến thể"></div>
        @if($context === 'dealer')
            <div><label for="price-tier" class="block text-sm font-semibold text-slate-700">Tier</label><select id="price-tier" name="tier_id" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"><option value="">Tất cả Tier</option>@foreach($tiers as $tier)<option value="{{ $tier->id }}" @selected((string)($filters['tier_id'] ?? '') === (string)$tier->id)>{{ $tier->name }}</option>@endforeach</select></div>
        @endif
        <div><label for="price-status" class="block text-sm font-semibold text-slate-700">Trạng thái bán</label><select id="price-status" name="status" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"><option value="">Tất cả</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Đang bán</option><option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Ngừng bán</option></select></div>
        <div><label for="price-state" class="block text-sm font-semibold text-slate-700">Tình trạng giá</label><select id="price-state" name="price_status" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"><option value="">Tất cả</option><option value="priced" @selected(($filters['price_status'] ?? '') === 'priced')>Đã có giá</option><option value="unpriced" @selected(($filters['price_status'] ?? '') === 'unpriced')>Chưa có giá</option></select></div>
        <div class="flex flex-wrap items-end gap-2 xl:col-span-4"><x-button type="submit">Lọc</x-button><a href="{{ route('admin.web.prices.'.$context.'.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-3 text-sm font-semibold">Xóa lọc</a></div>
    </form>

    @error('pricing')<x-alert tone="error">{{ $message }}</x-alert>@enderror
    <p class="text-sm text-slate-500">{{ $prices->total() }} SKU · Trang {{ $prices->currentPage() }}/{{ $prices->lastPage() }}</p>
    @if($groups->isEmpty())
        <x-empty-state title="Không tìm thấy bảng giá phù hợp." />
    @else
        <div class="space-y-3">
            @foreach($groups as $productId => $rows)
                <details class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" @if($loop->first || !empty($filters['search']) || !empty($filters['product_id'])) open @endif>
                    <summary class="flex cursor-pointer items-center gap-3 px-4 py-4 text-left hover:bg-slate-50">
                        @if($rows->first()['product_image_url'])<img src="{{ $rows->first()['product_image_url'] }}" alt="" loading="lazy" class="size-11 rounded-lg object-cover">@endif
                        <span class="min-w-0 flex-1"><span class="block truncate font-semibold text-junie-navy">{{ $rows->first()['product_name'] }}</span><span class="block text-xs text-slate-500">{{ $rows->first()['product_code'] }} · {{ $rows->groupBy('variant_id')->count() }} SKU trên trang này</span></span>
                    </summary>
                    @if($context === 'dealer')
                        <div class="border-t border-slate-100 px-4 py-2 text-right"><a href="{{ route('admin.web.prices.dealer.import.show', $productId) }}" class="text-sm font-semibold text-junie-navy hover:underline">Import giá đại lý cho sản phẩm →</a></div>
                    @endif
                    <div class="overflow-x-auto border-t border-slate-200">
                        <table class="w-full min-w-[760px] text-left text-sm {{ $context === 'dealer' ? 'lg:min-w-[930px]' : '' }}">
                            <thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3">Biến thể / SKU</th>@if($context === 'dealer')<th class="px-4 py-3">Tier</th><th class="px-4 py-3">Retail tham khảo</th>@endif<th class="px-4 py-3">Giá {{ $context === 'retail' ? 'Retail' : 'Đại lý' }}</th>@if($context === 'dealer')<th class="px-4 py-3">MOQ</th>@endif<th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3">Thao tác</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($rows as $row)
                                    <tr class="align-top">
                                        <td class="px-4 py-3"><span class="block font-semibold text-junie-navy">{{ $row['variant_name'] ?: 'Mặc định' }}</span><span class="break-all font-mono text-xs text-slate-500">{{ $row['sku'] }}</span><span class="block text-xs text-slate-500">{{ $row['unit_symbol'] ?: '—' }}</span></td>
                                        @if($context === 'dealer')<td class="px-4 py-3 font-medium">{{ $row['tier_name'] }}</td><td class="px-4 py-3">@if($row['retail_reference_price'] !== null)<x-formatted-number :value="$row['retail_reference_price']" :currency="true" />@else — @endif</td>@endif
                                        <td class="px-4 py-3 font-semibold text-junie-navy">@if($row['unit_price'] !== null)<x-formatted-number :value="$row['unit_price']" :currency="true" />@else<span class="font-normal text-slate-500">Chưa có giá</span>@endif</td>
                                        @if($context === 'dealer')<td class="px-4 py-3">{{ $row['minimum_quantity'] ?? '—' }}</td>@endif
                                        <td class="px-4 py-3"><x-product-status :status="$row['status'] === 'active' && $row['product_status'] === 'active' && $row['sellable'] ? 'active' : 'inactive'" /></td>
                                        <td class="px-4 py-3">
                                            <details @if($errors->any() && old('row_key') === $row['variant_id'].':'.($row['tier_id'] ?? 'retail')) open @endif>
                                                <summary class="cursor-pointer font-semibold text-junie-navy hover:underline">{{ $row['unit_price'] === null ? 'Thiết lập giá' : 'Sửa giá' }}</summary>
                                                <form method="POST" action="{{ $context === 'retail' ? route('admin.web.prices.retail.update', $row['variant_id']) : route('admin.web.prices.dealer.update', [$row['variant_id'], $row['tier_id']]) }}" class="mt-3 grid w-48 gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                                    @csrf @method($context === 'retail' ? 'PATCH' : 'PUT')
                                                    <input type="hidden" name="row_key" value="{{ $row['variant_id'] }}:{{ $row['tier_id'] ?? 'retail' }}">
                                                    <label class="text-xs font-semibold text-slate-700">Giá mới (VND)<input name="unit_price" type="number" min="{{ $context === 'retail' ? '0' : '0.01' }}" step="0.01" required value="{{ old('row_key') === $row['variant_id'].':'.($row['tier_id'] ?? 'retail') ? old('unit_price') : $row['unit_price'] }}" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-2 text-sm"></label>
                                                    @if($context === 'dealer')<label class="text-xs font-semibold text-slate-700">MOQ<input name="minimum_quantity" type="number" min="1" step="1" required value="{{ old('row_key') === $row['variant_id'].':'.$row['tier_id'] ? old('minimum_quantity') : ($row['minimum_quantity'] ?? 1) }}" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-2 text-sm"></label>@endif
                                                    @if(old('row_key') === $row['variant_id'].':'.($row['tier_id'] ?? 'retail'))
                                                        @error('unit_price')<p class="text-xs text-red-700">{{ $message }}</p>@enderror
                                                        @error('minimum_quantity')<p class="text-xs text-red-700">{{ $message }}</p>@enderror
                                                    @endif
                                                    <x-button type="submit">Lưu giá</x-button>
                                                </form>
                                            </details>
                                            @if($context === 'dealer' && $row['unit_price'] !== null)
                                                <x-confirm-modal :action="route('admin.web.prices.dealer.delete', [$row['variant_id'], $row['tier_id']])" method="DELETE" title="Xóa giá đại lý hiện tại" :message="'Xóa giá '.$row['tier_name'].' cho SKU '.$row['sku'].'? Lịch sử giá vẫn được giữ.'" confirm-label="Xóa giá" class="mt-2 text-xs font-semibold text-red-700 hover:underline"><x-slot:trigger>Xóa giá</x-slot:trigger></x-confirm-modal>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endforeach
        </div>
        <x-pagination :paginator="$prices" />
    @endif
</div>
@endsection
