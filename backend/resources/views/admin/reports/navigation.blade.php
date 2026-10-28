<nav class="flex flex-wrap gap-2" aria-label="Nhóm báo cáo">
    <a href="{{ route('admin.web.reports.index', $filters) }}" @if ($report === null) aria-current="page" @endif class="inline-flex min-h-10 items-center rounded-lg px-3 text-sm font-semibold {{ $report === null ? 'bg-junie-navy text-white' : 'border border-slate-300 bg-white text-junie-navy hover:bg-slate-50' }}">Tổng quan</a>
    @foreach ($reportNames as $key => $label)
        <a href="{{ route('admin.web.reports.show', ['report' => $key, ...$filters]) }}" @if ($report === $key) aria-current="page" @endif class="inline-flex min-h-10 items-center rounded-lg px-3 text-sm font-semibold {{ $report === $key ? 'bg-junie-navy text-white' : 'border border-slate-300 bg-white text-junie-navy hover:bg-slate-50' }}">{{ $label }}</a>
    @endforeach
</nav>
