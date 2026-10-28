@php
    $groups = [
        [
            'id' => 'overview', 'label' => 'Tổng quan', 'icon' => 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
            'items' => [
                ['label' => 'Tổng quan', 'route' => 'admin.web.dashboard', 'active' => request()->routeIs('admin.web.dashboard')],
                ['label' => 'Báo cáo ERP', 'route' => 'admin.web.reports.index', 'active' => request()->routeIs('admin.web.reports.*')],
            ],
        ],
        [
            'id' => 'products', 'label' => 'Quản lý sản phẩm', 'icon' => 'M12 2 3 7l9 5 9-5-9-5ZM3 7v10l9 5 9-5V7M12 12v10',
            'items' => [
                ['label' => 'Sản phẩm', 'route' => 'admin.web.products.index', 'active' => request()->routeIs('admin.web.products.*') && ! request()->routeIs('admin.web.products.drafts.*')],
                ['label' => 'Sản phẩm nháp', 'route' => 'admin.web.products.drafts.index', 'active' => request()->routeIs('admin.web.products.drafts.*')],
                ['label' => 'Danh mục', 'route' => 'admin.web.categories.index', 'active' => request()->routeIs('admin.web.categories.*')],
                ['label' => 'Thương hiệu', 'route' => 'admin.web.brands.index', 'active' => request()->routeIs('admin.web.brands.*')],
                ['label' => 'Đơn vị', 'route' => 'admin.web.units.index', 'active' => request()->routeIs('admin.web.units.*')],
            ],
        ],
        [
            'id' => 'stock', 'label' => 'Kho & tồn kho', 'icon' => 'M3 21V8l9-5 9 5v13M3 21h18M7 21v-8h10v8M3 10h18',
            'items' => [
                ['label' => 'Kho', 'route' => 'admin.web.warehouses.index', 'active' => request()->routeIs('admin.web.warehouses.*')],
                ['label' => 'Vùng phục vụ kho', 'route' => 'admin.web.warehouse-service-areas.index', 'active' => request()->routeIs('admin.web.warehouse-service-areas.*')],
                ['label' => 'Tồn kho', 'route' => 'admin.web.inventory.index', 'active' => request()->routeIs('admin.web.inventory.*')],
            ],
        ],
        [
            'id' => 'purchasing', 'label' => 'Mua hàng & nhập hàng', 'icon' => 'M3 6h11v10H3zM14 9h4l3 4v3h-7M7 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM18 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z',
            'items' => [
                ['label' => 'Nhà cung cấp', 'route' => 'admin.web.suppliers.index', 'active' => request()->routeIs('admin.web.suppliers.*')],
                ['label' => 'Phiếu mua / Nhận / Trả', 'route' => 'admin.web.purchase-orders.index', 'active' => request()->routeIs('admin.web.purchase-orders.*')],
            ],
        ],
        [
            'id' => 'sales', 'label' => 'Quản lý bán hàng', 'icon' => 'M3 4h2l2 12h12l2-9H6M9 21h.01M18 21h.01',
            'items' => [
                ['label' => 'Đơn bán hàng', 'route' => 'admin.web.sales.orders.index', 'active' => request()->routeIs('admin.web.sales.orders.*')],
                ['label' => 'Trả hàng & hoàn tiền', 'route' => 'admin.web.sales.returns.index', 'active' => request()->routeIs('admin.web.sales.returns.*')],
                ['label' => 'Khách hàng', 'route' => 'admin.web.customers.index', 'active' => request()->routeIs('admin.web.customers.*')],
            ],
        ],
        [
            'id' => 'pricing', 'label' => 'Giá bán & khuyến mãi', 'icon' => 'M3 11V4h7l11 11-7 7L3 11ZM7.5 7.5h.01',
            'items' => [
                ['label' => 'Bảng giá Retail', 'route' => 'admin.web.prices.retail.index', 'active' => request()->routeIs('admin.web.prices.retail.*')],
                ['label' => 'Bảng giá Đại lý', 'route' => 'admin.web.prices.dealer.index', 'active' => request()->routeIs('admin.web.prices.dealer.*')],
                ['label' => 'Khuyến mãi bán hàng', 'route' => 'admin.web.sales-promotions.index', 'active' => request()->routeIs('admin.web.sales-promotions.*')],
                ['label' => 'Voucher bán hàng', 'route' => 'admin.web.sales-vouchers.index', 'active' => request()->routeIs('admin.web.sales-vouchers.*')],
            ],
        ],
        [
            'id' => 'dealers', 'label' => 'Quản lý đại lý', 'icon' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
            'items' => [
                ['label' => 'Đơn đăng ký đại lý', 'route' => 'admin.web.dealer-applications.index', 'active' => request()->routeIs('admin.web.dealer-applications.*')],
                ['label' => 'Tài khoản đại lý', 'route' => 'admin.web.dealers.index', 'active' => request()->routeIs('admin.web.dealers.*')],
                ['label' => 'Tier đại lý', 'route' => 'admin.web.dealer-tiers.index', 'active' => request()->routeIs('admin.web.dealer-tiers.*')],
            ],
        ],
        [
            'id' => 'dealer-finance', 'label' => 'Tài chính đại lý', 'icon' => 'M3 6h16a2 2 0 0 1 2 2v12H5a2 2 0 0 1-2-2V6Zm0 2h16M16 14h5M17 14h.01',
            'items' => [
                ['label' => 'Ví đại lý', 'route' => 'admin.web.dealer-wallets.index', 'active' => request()->routeIs('admin.web.dealer-wallets.*')],
                ['label' => 'Yêu cầu nạp ví', 'route' => 'admin.web.dealer-wallet-deposits.index', 'active' => request()->routeIs('admin.web.dealer-wallet-deposits.*')],
            ],
        ],
        [
            'id' => 'utilities', 'label' => 'Hệ thống & tiện ích', 'icon' => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-1.8 1.8-.06-.06A1.7 1.7 0 0 0 16 18.4a1.7 1.7 0 0 0-1 1.56V20H9v-.04A1.7 1.7 0 0 0 8 18.4a1.7 1.7 0 0 0-1.94.34l-.06.06-1.8-1.8.06-.06A1.7 1.7 0 0 0 4.6 15 1.7 1.7 0 0 0 3.04 14H3v-4h.04A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.34-1.94L4.2 7l1.8-1.8.06.06A1.7 1.7 0 0 0 8 5.6 1.7 1.7 0 0 0 9 4.04V4h6v.04A1.7 1.7 0 0 0 16 5.6a1.7 1.7 0 0 0 1.94-.34l.06-.06L19.8 7l-.06.06A1.7 1.7 0 0 0 19.4 9a1.7 1.7 0 0 0 1.56 1H21v4h-.04A1.7 1.7 0 0 0 19.4 15Z',
            'items' => [
                ['label' => 'Xem trang giới thiệu Blade', 'route' => 'blade.admin', 'active' => request()->routeIs('blade.admin')],
                ['label' => 'Nhật ký hoạt động', 'route' => 'admin.web.audit-logs.index', 'active' => request()->routeIs('admin.web.audit-logs.*')],
                ['label' => 'Mở quản trị hiện tại', 'href' => rtrim((string) config('app.frontend_url'), '/').'/admin', 'active' => false],
            ],
        ],
    ];

    $activeGroup = null;
    foreach ($groups as $group) {
        foreach ($group['items'] as $item) {
            if ($item['active']) {
                $activeGroup = $group['id'];
                break 2;
            }
        }
    }
@endphp

<aside
    x-data="{ openGroup: @js($activeGroup), flyoutGroup: null, flyoutTop: 0 }"
    @resize.window="flyoutGroup = null"
    @keydown.escape.window="flyoutGroup = null"
    @click.outside="flyoutGroup = null"
    :class="[sidebarOpen ? 'translate-x-0' : '-translate-x-full', sidebarCollapsed ? 'lg:w-20' : 'lg:w-64']"
    class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-junie-navy text-white shadow-xl transition-[transform,width] duration-200 lg:translate-x-0 lg:shadow-none"
    aria-label="Điều hướng quản trị"
    data-admin-sidebar
>
    <div class="flex h-20 shrink-0 items-center justify-between gap-1 border-b border-white/10 px-3">
        <span class="min-w-0 truncate px-1 text-lg font-semibold tracking-wide" :class="sidebarCollapsed ? 'lg:hidden' : ''">Junie Admin</span>
        <span class="hidden px-1 text-lg font-bold text-junie-gold" :class="sidebarCollapsed ? 'lg:block' : 'lg:hidden'" aria-hidden="true">J</span>
        <button type="button" class="hidden shrink-0 rounded-lg p-2 text-slate-200 hover:bg-white/10 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-white lg:inline-flex" :aria-label="sidebarCollapsed ? 'Mở rộng điều hướng' : 'Thu gọn điều hướng'" :title="sidebarCollapsed ? 'Mở rộng điều hướng' : 'Thu gọn điều hướng'" @click="sidebarCollapsed = !sidebarCollapsed; flyoutGroup = null">
            <svg class="size-5 transition-transform duration-200" :class="sidebarCollapsed ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
        </button>
        <button type="button" class="rounded-lg p-2 text-white hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-white lg:hidden" aria-label="Đóng điều hướng" @click="sidebarOpen = false">✕</button>
    </div>

    <nav class="min-h-0 flex-1 space-y-1 overflow-y-auto overflow-x-hidden p-3" aria-label="Chức năng quản trị">
        @foreach ($groups as $group)
            @php($groupActive = collect($group['items'])->contains('active', true))
            <div>
                <button
                    type="button"
                    class="flex min-h-11 w-full items-center gap-3 rounded-xl px-3 text-left text-sm font-semibold transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white {{ $groupActive ? 'bg-white/10 text-white' : 'text-slate-200 hover:bg-white/10 hover:text-white' }}"
                    :class="sidebarCollapsed ? 'lg:justify-center lg:px-2' : ''"
                    :title="sidebarCollapsed ? @js($group['label']) : null"
                    :aria-expanded="sidebarCollapsed && window.innerWidth >= 1024 ? flyoutGroup === @js($group['id']) : openGroup === @js($group['id'])"
                    :aria-controls="sidebarCollapsed && window.innerWidth >= 1024 ? @js('admin-sidebar-flyout-'.$group['id']) : @js('admin-sidebar-panel-'.$group['id'])"
                    @click="if (sidebarCollapsed && window.innerWidth >= 1024) { flyoutGroup = flyoutGroup === @js($group['id']) ? null : @js($group['id']); flyoutTop = Math.min($el.getBoundingClientRect().top, Math.max(16, window.innerHeight - 320)) } else { openGroup = openGroup === @js($group['id']) ? null : @js($group['id']); flyoutGroup = null }"
                >
                    <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $group['icon'] }}" /></svg>
                    <span class="min-w-0 flex-1 truncate" :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ $group['label'] }}</span>
                    <svg class="size-4 shrink-0 transition-transform duration-200" :class="[sidebarCollapsed ? 'lg:hidden' : '', openGroup === @js($group['id']) ? 'rotate-180' : '']" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
                </button>
                <div id="admin-sidebar-panel-{{ $group['id'] }}" x-cloak x-show="openGroup === @js($group['id'])" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-1" :class="sidebarCollapsed ? 'lg:hidden' : ''" class="space-y-1 pb-2 pl-8 pt-1">
                    @foreach ($group['items'] as $item)
                        <a href="{{ isset($item['route']) ? route($item['route']) : $item['href'] }}" @if ($item['active']) :aria-current="sidebarCollapsed && window.innerWidth >= 1024 ? null : 'page'" @endif @click="sidebarOpen = false; flyoutGroup = null" class="flex min-h-10 items-center rounded-lg px-3 py-2 text-sm transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white {{ $item['active'] ? 'bg-white text-junie-navy font-semibold' : 'text-slate-200 hover:bg-white/10 hover:text-white' }}">{{ $item['label'] }}</a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>

    @foreach ($groups as $group)
        <div id="admin-sidebar-flyout-{{ $group['id'] }}" x-cloak x-show="sidebarCollapsed && flyoutGroup === @js($group['id']) && window.innerWidth >= 1024" :style="{ top: flyoutTop + 'px' }" class="fixed left-20 z-50 max-h-[calc(100dvh-2rem)] w-64 overflow-y-auto rounded-r-xl border border-white/10 bg-junie-navy p-3 shadow-2xl" role="group" aria-label="{{ $group['label'] }}">
            <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wide text-junie-gold">{{ $group['label'] }}</p>
            @foreach ($group['items'] as $item)
                <a href="{{ isset($item['route']) ? route($item['route']) : $item['href'] }}" @if ($item['active']) :aria-current="sidebarCollapsed ? 'page' : null" @endif @click="flyoutGroup = null" class="flex min-h-10 items-center rounded-lg px-3 py-2 text-sm transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white {{ $item['active'] ? 'bg-white text-junie-navy font-semibold' : 'text-slate-200 hover:bg-white/10 hover:text-white' }}">{{ $item['label'] }}</a>
            @endforeach
        </div>
    @endforeach
</aside>
