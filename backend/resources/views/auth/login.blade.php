@extends('layouts.guest')

@section('title', 'Đăng nhập')
@section('heading', 'Đăng nhập tài khoản')
@section('description', 'Tiếp tục sử dụng tài khoản Junie hiện tại của bạn.')

@section('content')
    <form method="POST" action="{{ route('blade.login') }}" class="grid gap-5">
        @csrf
        @if ($returnTo ?? null)
            <input type="hidden" name="return_to" value="{{ $returnTo }}">
        @endif
        <div>
            <label for="email" class="mb-2 block text-sm font-medium text-slate-700">Email</label>
            <input id="email" name="email" type="email" autocomplete="email" required value="{{ old('email') }}"
                class="min-h-11 w-full rounded-xl border px-3 py-2 outline-none focus:border-junie-navy focus:ring-2 focus:ring-blue-100 @error('email') border-red-500 @else border-slate-300 @enderror">
            @error('email') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="password" class="mb-2 block text-sm font-medium text-slate-700">Mật khẩu</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required
                class="min-h-11 w-full rounded-xl border px-3 py-2 outline-none focus:border-junie-navy focus:ring-2 focus:ring-blue-100 @error('password') border-red-500 @else border-slate-300 @enderror">
            @error('password') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <x-button type="submit" class="w-full">Đăng nhập</x-button>
    </form>
    <p class="mt-6 text-center text-sm text-slate-600">Chưa có tài khoản? <a href="{{ route('register') }}" class="font-semibold text-junie-navy hover:underline">Tạo tài khoản</a></p>
@endsection
