@extends('layouts.guest')

@section('title', 'Tạo tài khoản')
@section('heading', 'Tạo tài khoản')
@section('description', 'Tạo tài khoản khách hàng để sử dụng dịch vụ Junie.')

@section('content')
    <form method="POST" action="{{ route('blade.register') }}" class="grid gap-5">
        @csrf
        <div>
            <label for="name" class="mb-2 block text-sm font-medium text-slate-700">Họ và tên</label>
            <input id="name" name="name" autocomplete="name" required value="{{ old('name') }}"
                class="min-h-11 w-full rounded-xl border px-3 py-2 outline-none focus:border-junie-navy focus:ring-2 focus:ring-blue-100 @error('name') border-red-500 @else border-slate-300 @enderror">
            @error('name') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="email" class="mb-2 block text-sm font-medium text-slate-700">Email</label>
            <input id="email" name="email" type="email" autocomplete="email" required value="{{ old('email') }}"
                class="min-h-11 w-full rounded-xl border px-3 py-2 outline-none focus:border-junie-navy focus:ring-2 focus:ring-blue-100 @error('email') border-red-500 @else border-slate-300 @enderror">
            @error('email') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="phone" class="mb-2 block text-sm font-medium text-slate-700">Số điện thoại (không bắt buộc)</label>
            <input id="phone" name="phone" type="tel" autocomplete="tel" value="{{ old('phone') }}"
                class="min-h-11 w-full rounded-xl border px-3 py-2 outline-none focus:border-junie-navy focus:ring-2 focus:ring-blue-100 @error('phone') border-red-500 @else border-slate-300 @enderror">
            @error('phone') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="password" class="mb-2 block text-sm font-medium text-slate-700">Mật khẩu</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required minlength="8"
                class="min-h-11 w-full rounded-xl border px-3 py-2 outline-none focus:border-junie-navy focus:ring-2 focus:ring-blue-100 @error('password') border-red-500 @else border-slate-300 @enderror">
            @error('password') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="password_confirmation" class="mb-2 block text-sm font-medium text-slate-700">Xác nhận mật khẩu</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="8"
                class="min-h-11 w-full rounded-xl border border-slate-300 px-3 py-2 outline-none focus:border-junie-navy focus:ring-2 focus:ring-blue-100">
        </div>
        <x-button type="submit" class="w-full">Tạo tài khoản</x-button>
    </form>
    <p class="mt-6 text-center text-sm text-slate-600">Đã có tài khoản? <a href="{{ route('login') }}" class="font-semibold text-junie-navy hover:underline">Đăng nhập</a></p>
@endsection
