@extends('layouts.dealer')

@section('title', $address ? 'Sửa địa chỉ giao hàng' : 'Thêm địa chỉ giao hàng')
@section('page-title', $address ? 'Sửa địa chỉ giao hàng' : 'Thêm địa chỉ giao hàng')

@section('content')
<div class="mx-auto max-w-3xl space-y-6"><header><h1 class="text-3xl font-semibold text-junie-navy">{{ $address ? 'Sửa địa chỉ giao hàng' : 'Thêm địa chỉ giao hàng' }}</h1><p class="mt-2 text-sm text-slate-600">Chọn tỉnh trước, sau đó chọn phường / xã tương ứng. Kho cho đơn mới được xác định khi xem trước đơn.</p></header>
    <form method="POST" action="{{ $address ? route('dealer.web.addresses.update', $address->id) : route('dealer.web.addresses.store') }}" x-data="dealerAddressForm()" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        @csrf
        @if ($address)@method('PUT')@endif
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block text-sm font-semibold">Người nhận *<input name="recipient_name" required maxlength="255" value="{{ old('recipient_name', $address?->recipient_name) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal">@error('recipient_name')<span class="mt-1 block text-red-700">{{ $message }}</span>@enderror</label>
            <label class="block text-sm font-semibold">Số điện thoại *<input name="recipient_phone" type="tel" required maxlength="50" value="{{ old('recipient_phone', $address?->recipient_phone) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal">@error('recipient_phone')<span class="mt-1 block text-red-700">{{ $message }}</span>@enderror</label>
            <label class="block text-sm font-semibold">Tỉnh / thành *<select name="province_code" required x-model="provinceCode" @change="wardCode = ''; loadWards()" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal"><option value="">Chọn tỉnh / thành</option>@foreach ($provinces as $province)<option value="{{ $province->code }}">{{ $province->name }}</option>@endforeach</select>@error('province_code')<span class="mt-1 block text-red-700">{{ $message }}</span>@enderror</label>
            <label class="block text-sm font-semibold">Phường / xã *<select name="ward_code" required x-model="wardCode" :disabled="!provinceCode" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal"><option value="">Chọn phường / xã</option><template x-for="ward in wards" :key="ward.code"><option :value="ward.code" x-text="ward.name"></option></template></select>@error('ward_code')<span class="mt-1 block text-red-700">{{ $message }}</span>@enderror</label>
            <label class="block text-sm font-semibold sm:col-span-2">Địa chỉ chi tiết *<input name="address_line" required maxlength="255" value="{{ old('address_line', $address?->address_line) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal">@error('address_line')<span class="mt-1 block text-red-700">{{ $message }}</span>@enderror</label>
            <label class="block text-sm font-semibold">Mã bưu chính<input name="postal_code" maxlength="30" value="{{ old('postal_code', $address?->postal_code) }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal">@error('postal_code')<span class="mt-1 block text-red-700">{{ $message }}</span>@enderror</label>
        </div>
        <input type="hidden" name="is_default" value="0"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $address?->is_default ?? false))>Đặt làm địa chỉ mặc định</label>@error('is_default')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
        <div class="flex flex-wrap gap-3"><button class="min-h-11 rounded-xl bg-junie-navy px-5 text-sm font-semibold text-white">Lưu địa chỉ</button><a href="{{ route('dealer.web.addresses.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm font-semibold">Hủy</a></div>
    </form>
</div>
<script>
function dealerAddressForm() {
    return {
        provinceCode: @js((string) $selectedProvince), wardCode: @js((string) $selectedWard),
        wards: @js($wards->map(fn ($ward) => ['code' => $ward->code, 'name' => $ward->name])->all()),
        async loadWards() {
            if (!this.provinceCode) { this.wards = []; return; }
            try {
                const response = await fetch(@js(url('/api/administrative/wards')) + '?' + new URLSearchParams({province_code: this.provinceCode}), {headers: {'Accept': 'application/json'}});
                this.wards = response.ok ? (await response.json()).data : [];
            } catch { this.wards = []; }
        },
    };
}
</script>
@endsection
