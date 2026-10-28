@extends('layouts.app')

@section('body')
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6">
            <a href="{{ route('retail.web.shop.index') }}" class="text-xl font-bold tracking-wide text-junie-navy">JUNIE</a>
            <nav aria-label="Mua sắm" class="flex flex-wrap items-center gap-4 text-sm font-semibold text-slate-700">
                <a href="{{ route('retail.web.shop.index') }}" class="hover:text-junie-navy" @if(request()->routeIs('retail.web.shop.*')) aria-current="page" @endif>Sản phẩm</a>
                @if (auth()->user()?->isCustomer())
                    <a href="{{ route('retail.web.cart.index') }}" class="hover:text-junie-navy" @if(request()->routeIs('retail.web.cart.*')) aria-current="page" @endif>Giỏ hàng</a>
                    <a href="{{ route('retail.web.orders.index') }}" class="hover:text-junie-navy" @if(request()->routeIs('retail.web.orders.*')) aria-current="page" @endif>Đơn hàng & trả hàng</a>
                    <a href="{{ route('retail.web.profile.show') }}" class="hover:text-junie-navy" @if(request()->routeIs('retail.web.profile.*')) aria-current="page" @endif>Thông tin cá nhân</a>
                @endif
                <a href="{{ route('retail.web.dealer-applications.create') }}" class="hover:text-junie-navy" @if(request()->routeIs('retail.web.dealer-applications.*')) aria-current="page" @endif>Đại lý Junie</a>
                @auth
                    <form method="POST" action="{{ route('blade.logout') }}">@csrf
                        <button type="submit" class="hover:text-junie-navy">Đăng xuất</button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="hover:text-junie-navy">Đăng nhập</a>
                @endauth
            </nav>
        </div>
    </header>
    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:py-10">
        <x-alerts />
        @if ($errors->any())
            <div class="mb-5 space-y-2" role="alert">
                @foreach ($errors->all() as $message)
                    <x-alert tone="error">{{ $message }}</x-alert>
                @endforeach
            </div>
        @endif
        @yield('content')
    </main>
@endsection
