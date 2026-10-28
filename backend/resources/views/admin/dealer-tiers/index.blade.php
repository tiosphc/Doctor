@extends('layouts.admin')

@section('title', 'Tier đại lý')
@section('page-title', 'Tier đại lý')

@section('content')
<div class="space-y-6">
    <header><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Sản phẩm & giá</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">Tier đại lý</h1><p class="mt-2 text-sm text-slate-600">Ba hạng cố định: Silver, Gold, Diamond. Đại lý mới bắt đầu ở Tier mặc định; MOQ là quy tắc giá riêng.</p></header>
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex flex-wrap items-center justify-between gap-4"><div><h2 class="text-lg font-semibold text-junie-navy">Tự động xét Tier theo doanh thu</h2><p class="mt-1 text-sm text-slate-600">{{ $policy['enabled'] ? 'Đang bật' : 'Đang tắt' }} · Chu kỳ {{ $policy['revenue_window_months'] }} tháng · Kỳ xét tiếp theo {{ $policy['next_evaluation_at'] }}</p><p class="mt-1 text-xs text-slate-500">Chỉ bật khi ngưỡng của cả ba Tier hợp lệ và tăng dần từ Silver = 0.</p></div><x-confirm-modal :action="route('admin.web.dealer-tiers.auto-policy.update')" method="PUT" :title="$policy['enabled'] ? 'Tắt xét Tier tự động' : 'Bật xét Tier tự động'" :message="$policy['enabled'] ? 'Tắt đánh giá Tier tự động?' : 'Bật đánh giá Tier tự động theo ngưỡng đã cấu hình?'" :confirm-label="$policy['enabled'] ? 'Tắt tự động' : 'Bật tự động'" :fields="['enabled' => $policy['enabled'] ? '0' : '1']" tone="primary" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold"><x-slot:trigger>{{ $policy['enabled'] ? 'Tắt tự động' : 'Bật tự động' }}</x-slot:trigger></x-confirm-modal></div></section>
    @if($tiers->isEmpty())<x-empty-state title="Chưa có Tier đại lý được cấu hình." />@else
        <div class="grid gap-4 lg:grid-cols-3">
            @foreach($tiers as $tier)
                <form method="POST" action="{{ route('admin.web.dealer-tiers.update', $tier) }}" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">@csrf @method('PATCH')
                    <input type="hidden" name="_tier_id" value="{{ $tier->id }}">
                    <div><x-badge>{{ $tier->code }}</x-badge><h2 class="mt-2 text-xl font-semibold text-junie-navy">{{ $tier->name }}</h2><p class="mt-1 text-xs text-slate-500">{{ $tier->is_default_initial ? 'Tier ban đầu' : 'Tier nâng hạng' }} · {{ $tier->status }}</p></div>
                    <label class="block text-sm font-semibold">Ngưỡng doanh thu VND
                        @if($tier->code === 'SILVER')<input value="0" disabled class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 bg-slate-50 px-3">@else<input name="revenue_threshold" inputmode="numeric" pattern="[1-9][0-9]*" maxlength="18" required value="{{ (string) old('_tier_id') === (string) $tier->id ? old('revenue_threshold') : ($tier->revenue_threshold === null ? '' : explode('.', (string) $tier->revenue_threshold)[0]) }}" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal">@endif
                    </label>
                    <label class="block text-sm font-semibold">Mô tả<textarea name="description" rows="3" maxlength="2000" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal">{{ (string) old('_tier_id') === (string) $tier->id ? old('description') : $tier->description }}</textarea></label>
                    <x-button type="submit" x-bind:disabled="submitting">Lưu cấu hình</x-button>
                </form>
            @endforeach
        </div>
    @endif
</div>
@endsection
