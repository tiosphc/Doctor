@extends('layouts.customer')

@section('title', $product->name)

@section('content')
    <a href="{{ route('retail.web.shop.index') }}" class="text-sm font-semibold text-junie-navy hover:underline">← Sản phẩm</a>
    <div class="mt-5 grid gap-8 lg:grid-cols-2">
        <div class="aspect-square overflow-hidden rounded-2xl bg-slate-100">
            @if ($product->images->first())
                <img src="{{ $product->images->first()->url }}" alt="{{ $product->name }}" class="h-full w-full object-cover">
            @endif
        </div>
        <div class="space-y-5">
            <div><h1 class="text-3xl font-semibold text-junie-navy">{{ $product->name }}</h1>
                <p class="mt-2 text-sm text-slate-500">{{ $product->product_code }}</p></div>
            @if ($product->description)
                <p class="whitespace-pre-line text-sm leading-7 text-slate-700">{{ $product->description }}</p>
            @endif
            <form method="POST" action="{{ route('retail.web.cart.items.add') }}" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
                @csrf
                <label class="block text-sm font-semibold" for="retail-variant">Quy cách / SKU</label>
                <select id="retail-variant" name="product_variant_id" required class="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm">
                    @foreach ($variants as $variant)
                        <option value="{{ $variant['id'] }}" @selected((int) old('product_variant_id', $variants->first()['id']) === $variant['id'])>{{ $variant['variant_name'] }} · {{ $variant['sku'] }} · {{ number_format((float) $variant['price'], 0, ',', '.') }} ₫ / {{ $variant['unit'] }}</option>
                    @endforeach
                </select>
                <label class="block max-w-36 text-sm font-semibold" for="retail-quantity">Số lượng</label>
                <input id="retail-quantity" name="quantity" type="number" min="1" step="1" value="{{ old('quantity', 1) }}" required class="min-h-11 w-36 rounded-xl border border-slate-300 px-3 text-sm">
                <p class="text-xs text-slate-500">Giá và tồn kho sẽ được kiểm tra lại khi thêm vào giỏ và khi đặt hàng.</p>
                @if (auth()->user()?->isCustomer())
                    <x-button type="submit" x-bind:disabled="submitting">Thêm vào giỏ</x-button>
                @elseif (auth()->guest())
                    <a href="{{ route('login', ['return_to' => request()->path()]) }}" class="inline-flex min-h-11 items-center rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Đăng nhập để thêm vào giỏ</a>
                @else
                    <p class="text-sm text-slate-600">Giỏ hàng chỉ dành cho tài khoản khách hàng.</p>
                @endif
            </form>
        </div>
    </div>
@endsection
