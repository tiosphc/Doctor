@extends('layouts.app')

@section('body')
    <div class="min-h-screen lg:flex" x-data="{ sidebarOpen: false }">
        <div x-cloak x-show="sidebarOpen" class="fixed inset-0 z-40 bg-slate-950/50 lg:hidden" @click="sidebarOpen = false"></div>
        <x-sidebar title="Đại lý Junie" :items="[
            ['label' => 'Sản phẩm đại lý', 'href' => route('dealer.web.products.index'), 'active' => request()->routeIs('dealer.web.products.*')],
            ['label' => 'Đơn hàng & trả hàng', 'href' => route('dealer.web.orders.index'), 'active' => request()->routeIs('dealer.web.orders.*')],
            ['label' => 'Đặt hàng nhanh', 'href' => route('dealer.web.quick.create'), 'active' => request()->routeIs('dealer.web.quick.*')],
            ['label' => 'Nhập đơn Excel', 'href' => route('dealer.web.imports.index'), 'active' => request()->routeIs('dealer.web.imports.*')],
            ['label' => 'Ví & nạp tiền', 'href' => route('dealer.web.wallet.index'), 'active' => request()->routeIs('dealer.web.wallet.*')],
            ['label' => 'Hồ sơ đại lý', 'href' => route('dealer.web.profile.index'), 'active' => request()->routeIs('dealer.web.profile.*')],
            ['label' => 'Địa chỉ giao hàng', 'href' => route('dealer.web.addresses.index'), 'active' => request()->routeIs('dealer.web.addresses.*')],
            ['label' => 'Ưu đãi đại lý', 'href' => route('dealer.web.promotions.index'), 'active' => request()->routeIs('dealer.web.promotions.*')],
            ['label' => 'Mua sắm Retail', 'href' => route('retail.web.shop.index'), 'active' => false],
            ['label' => 'Đơn hàng Retail', 'href' => route('retail.web.orders.index'), 'active' => false],
            ['label' => 'Thông tin cá nhân', 'href' => route('retail.web.profile.show'), 'active' => request()->routeIs('retail.web.profile.*')],
            ['label' => 'Mở cổng đại lý hiện tại', 'href' => rtrim((string) config('app.frontend_url'), '/').'/dealer', 'active' => false],
        ]" />
        <div class="min-w-0 flex-1 lg:pl-64">
            <x-topbar :title="trim($__env->yieldContent('page-title', 'Khu vực đại lý'))">
                @isset($account)
                    <div class="flex flex-wrap items-center gap-2">
                        <x-badge>{{ $effectiveTier['name'] ?? 'Chưa có hạng' }}</x-badge>
                        <x-badge tone="gold">Ví: {{ $account->wallet ? number_format((int) $account->wallet->balance, 0, ',', '.') . 'đ' : '—' }}</x-badge>
                    </div>
                @endisset
            </x-topbar>
            <main class="mx-auto max-w-7xl px-4 py-7 sm:px-6 lg:px-8">
                <x-alerts />
                @yield('content')
            </main>
        </div>
    </div>
@endsection
