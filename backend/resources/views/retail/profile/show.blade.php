@extends('layouts.customer')

@section('title', 'Thông tin cá nhân')

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <header>
            <p class="text-xs font-semibold uppercase tracking-widest text-junie-gold">Tài khoản Junie</p>
            <h1 class="mt-2 text-3xl font-semibold text-junie-navy">Thông tin cá nhân</h1>
            <p class="mt-2 text-sm text-slate-600">Cập nhật thông tin liên hệ để Junie hỗ trợ bạn tốt hơn.</p>
        </header>

        <form method="POST" action="{{ route('retail.web.profile.update') }}" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            @csrf
            @method('PATCH')
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="profile-name" class="block text-sm font-semibold text-slate-700">Họ và tên <span aria-hidden="true">*</span></label>
                    <input id="profile-name" name="name" type="text" value="{{ old('name', $user->name) }}" maxlength="255" autocomplete="name" required
                        @error('name') aria-invalid="true" aria-describedby="profile-name-error" @enderror
                        class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm">
                    @error('name') <p id="profile-name-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="profile-phone" class="block text-sm font-semibold text-slate-700">Số điện thoại</label>
                    <input id="profile-phone" name="phone" type="tel" value="{{ old('phone', $user->phone) }}" maxlength="30" inputmode="tel" autocomplete="tel"
                        @error('phone') aria-invalid="true" aria-describedby="profile-phone-error" @enderror
                        class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm">
                    @error('phone') <p id="profile-phone-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <p class="text-sm font-semibold text-slate-700">Email đăng nhập</p>
                <p class="mt-1 rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-700">{{ $user->email }}</p>
                <p class="mt-1 text-xs text-slate-500">Email được dùng để đăng nhập và không thể thay đổi tại đây.</p>
            </div>
            <div class="flex flex-wrap items-center gap-4">
                <x-button type="submit">Lưu thông tin</x-button>
                <a href="{{ route('retail.web.orders.index') }}" class="inline-flex min-h-11 items-center text-sm font-semibold text-junie-navy hover:underline">Xem đơn hàng</a>
            </div>
        </form>
    </div>
@endsection
