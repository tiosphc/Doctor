<section class="space-y-6" aria-labelledby="operations-heading">
    <h2 id="operations-heading" class="text-xl font-semibold text-junie-navy">Vận hành phòng khám</h2>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <a href="{{ $reactAdminUrl }}/doctors" class="block hover:opacity-80"><x-dashboard-metric label="Bác sĩ"><x-formatted-number :value="$dashboard['overview']['doctors']" /></x-dashboard-metric></a>
        <a href="{{ $reactAdminUrl }}/services" class="block hover:opacity-80"><x-dashboard-metric label="Dịch vụ"><x-formatted-number :value="$dashboard['overview']['services']" /></x-dashboard-metric></a>
        <a href="{{ $reactAdminUrl }}/appointments" class="block hover:opacity-80"><x-dashboard-metric label="Lịch hẹn"><x-formatted-number :value="$dashboard['overview']['appointments']" /></x-dashboard-metric></a>
        <a href="{{ route('admin.web.customers.index') }}" class="block hover:opacity-80"><x-dashboard-metric label="Khách hàng"><x-formatted-number :value="$dashboard['overview']['customers']" /></x-dashboard-metric></a>
    </div>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="today-heading">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Vận hành trong ngày</p><h3 id="today-heading" class="mt-1 text-xl font-semibold text-junie-navy">Tình hình hôm nay</h3></div>
            <p class="text-sm text-slate-500">{{ \Carbon\CarbonImmutable::parse($dashboard['today']['date'])->format('d/m/Y') }}</p>
        </div>
        <div class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
            @foreach ([
                ['label' => 'Lịch hôm nay', 'value' => $dashboard['today']['appointments']],
                ['label' => 'Chờ check-in', 'value' => $dashboard['today']['waiting_checkin']],
                ['label' => 'Đang khám', 'value' => $dashboard['today']['in_progress']],
                ['label' => 'Hoàn thành', 'value' => $dashboard['today']['completed']],
                ['label' => 'Đã hủy', 'value' => $dashboard['today']['cancelled']],
            ] as $item)
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <strong class="text-2xl text-junie-navy"><x-formatted-number :value="$item['value']" /></strong>
                    <p class="mt-2 text-xs text-slate-500">{{ $item['label'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="appointments-heading">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-5 sm:px-6">
            <div><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Điều phối</p><h3 id="appointments-heading" class="mt-1 text-xl font-semibold text-junie-navy">Lịch hẹn hôm nay</h3></div>
            <a href="{{ $reactAdminUrl }}/appointments" class="text-sm font-semibold text-junie-navy hover:underline">Xem tất cả lịch hẹn →</a>
        </div>
        @if (count($dashboard['today_appointments']) === 0)
            <p class="px-5 py-10 text-center text-sm text-slate-500">Chưa có lịch hẹn nào trong hôm nay.</p>
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[650px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-6 py-3 font-medium">Giờ</th><th class="px-4 py-3 font-medium">Khách hàng</th><th class="px-4 py-3 font-medium">Bác sĩ</th><th class="px-4 py-3 font-medium">Dịch vụ</th><th class="px-6 py-3 text-right font-medium">Trạng thái</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($dashboard['today_appointments'] as $appointment)
                            <tr>
                                <td class="px-6 py-4 font-semibold text-junie-navy">{{ substr($appointment['start_time'], 0, 5) }}</td>
                                <td class="px-4 py-4"><a href="{{ $reactAdminUrl }}/appointments/{{ $appointment['id'] }}" class="font-semibold text-junie-navy hover:underline">{{ $appointment['customer_name'] ?: 'Khách vãng lai' }}</a>@if ($appointment['booking_code'])<p class="mt-1 text-xs text-slate-500">{{ $appointment['booking_code'] }}</p>@endif</td>
                                <td class="px-4 py-4 text-slate-600">{{ $appointment['doctor_name'] }}</td>
                                <td class="px-4 py-4 text-slate-600">{{ $appointment['service_name'] }}</td>
                                <td class="px-6 py-4 text-right"><x-appointment-status :status="$appointment['status']" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="divide-y divide-slate-100 md:hidden">
                @foreach ($dashboard['today_appointments'] as $appointment)
                    <a href="{{ $reactAdminUrl }}/appointments/{{ $appointment['id'] }}" class="block p-5 hover:bg-slate-50">
                        <div class="flex flex-wrap items-start justify-between gap-2"><p class="font-semibold text-junie-navy">{{ substr($appointment['start_time'], 0, 5) }} · {{ $appointment['customer_name'] ?: 'Khách vãng lai' }}</p><x-appointment-status :status="$appointment['status']" /></div>
                        <p class="mt-1 text-sm text-slate-500">{{ $appointment['doctor_name'] }} · {{ $appointment['service_name'] }}</p>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.65fr)_minmax(280px,0.75fr)]">
        <section class="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="statistics-heading">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Xu hướng vận hành</p><h3 id="statistics-heading" class="mt-1 text-xl font-semibold text-junie-navy">Thống kê lịch hẹn</h3></div>
                <nav class="flex flex-wrap gap-2" aria-label="Khoảng thời gian thống kê">
                    @foreach ([['value' => '7_days', 'label' => '7 ngày'], ['value' => '30_days', 'label' => '30 ngày'], ['value' => 'month', 'label' => 'Tháng này']] as $option)
                        <a href="{{ route('admin.web.dashboard', ['period' => $option['value']]) }}" @if ($period === $option['value']) aria-current="page" @endif class="rounded-full border px-3 py-2 text-xs font-semibold {{ $period === $option['value'] ? 'border-junie-navy bg-junie-navy text-white' : 'border-slate-200 text-slate-600 hover:border-junie-gold' }}">{{ $option['label'] }}</a>
                    @endforeach
                </nav>
            </div>
            <p class="mt-2 text-xs text-slate-500">{{ $dashboard['appointment_statistics']['from'] }} – {{ $dashboard['appointment_statistics']['to'] }}</p>
            <div class="mt-6 h-72 min-w-0"><canvas id="appointments-chart" role="img" aria-label="Biểu đồ tổng số lịch hẹn, hoàn thành và đã hủy theo ngày"></canvas></div>
        </section>
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="actions-heading">
            <p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Ưu tiên vận hành</p><h3 id="actions-heading" class="mt-1 text-xl font-semibold text-junie-navy">Cần xử lý</h3>
            @if (count($dashboard['actions_required']) === 0)
                <p class="mt-8 rounded-xl border border-emerald-200 bg-emerald-50 p-5 text-center text-sm text-emerald-800">Hiện không có vấn đề nào cần xử lý.</p>
            @else
                <ul class="mt-5 grid gap-3">
                    @foreach ($dashboard['actions_required'] as $action)
                        <li><a href="{{ $reactBaseUrl.$action['url'] }}" class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600 hover:border-junie-gold"><span class="min-w-0 flex-1"><strong class="text-junie-navy"><x-formatted-number :value="$action['count']" /></strong> {{ $action['label'] }}</span><span aria-hidden="true">→</span></a></li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="activities-heading">
            <p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Nhật ký hệ thống</p><h3 id="activities-heading" class="mt-1 text-xl font-semibold text-junie-navy">Hoạt động gần đây</h3>
            @if (! $dashboard['recent_activities']['available'])
                <p class="mt-6 rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">Hệ thống chưa có Audit Log nên chưa thể hiển thị hoạt động gần đây.</p>
            @elseif (count($dashboard['recent_activities']['items']) === 0)
                <p class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-6 text-center text-sm text-slate-500">Chưa có hoạt động nào được ghi nhận.</p>
            @else
                <ul class="mt-5 divide-y divide-slate-100">@foreach ($dashboard['recent_activities']['items'] as $activity)<li class="py-3 text-sm"><p class="font-semibold text-junie-navy">{{ $activity['actor'] }}</p><p class="mt-1 text-slate-500">{{ $activity['action'] }} · {{ $activity['subject'] }}</p></li>@endforeach</ul>
                <a href="{{ route('admin.web.audit-logs.index') }}" class="mt-4 inline-flex text-sm font-semibold text-junie-navy hover:underline">Xem nhật ký hoạt động →</a>
            @endif
        </section>
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="doctors-heading">
            <p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Phân bổ trong ngày</p><h3 id="doctors-heading" class="mt-1 text-xl font-semibold text-junie-navy">Bác sĩ hôm nay</h3>
            @forelse ($dashboard['doctor_today'] as $doctor)
                <div class="flex items-center gap-4 border-b border-slate-100 py-3 last:border-0"><div class="min-w-0 flex-1"><p class="truncate font-semibold text-junie-navy">{{ $doctor['name'] }}</p><p class="mt-1 text-xs text-slate-500">{{ $doctor['completed'] }} hoàn thành · {{ $doctor['in_progress'] }} đang khám</p></div><strong class="text-sm text-junie-navy">{{ $doctor['appointments'] }} lịch</strong></div>
            @empty
                <p class="mt-6 rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">Chưa có bác sĩ nào có lịch trong hôm nay.</p>
            @endforelse
        </section>
    </div>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="services-heading">
        <p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Tháng hiện tại</p><h3 id="services-heading" class="mt-1 text-xl font-semibold text-junie-navy">Dịch vụ được đặt nhiều</h3>
        @if (count($dashboard['popular_services']) === 0)
            <p class="mt-6 rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">Chưa có lượt đặt dịch vụ nào trong tháng này.</p>
        @else
            @php($maximumServiceCount = max(array_column($dashboard['popular_services'], 'appointments')))
            <div class="mt-5 grid gap-x-8 gap-y-5 md:grid-cols-2">
                @foreach ($dashboard['popular_services'] as $service)
                    <div><div class="flex items-center justify-between gap-4 text-sm"><span class="truncate font-semibold text-junie-navy">{{ $service['name'] }}</span><span class="shrink-0 text-slate-500">{{ $service['appointments'] }} lượt</span></div><progress class="mt-2 h-2 w-full accent-junie-gold" value="{{ $service['appointments'] }}" max="{{ $maximumServiceCount }}" aria-label="{{ $service['name'] }}: {{ $service['appointments'] }} lượt"></progress></div>
                @endforeach
            </div>
        @endif
    </section>
</section>
