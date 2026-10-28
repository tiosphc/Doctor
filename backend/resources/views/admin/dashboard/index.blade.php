@extends('layouts.admin')

@section('title', 'Tổng quan hệ thống')
@section('page-title', 'Tổng quan hệ thống')

@section('content')
    <div class="space-y-7">
        <header>
            <h1 class="text-2xl font-semibold text-junie-navy sm:text-3xl">Tổng quan hệ thống</h1>
            <p class="mt-2 text-sm text-slate-600">Theo dõi tình hình hoạt động, lịch hẹn và các thông tin cần xử lý.</p>
        </header>

        @include('admin.dashboard.erp')
        @include('admin.dashboard.operations')
    </div>

    <script>
        window.adminDashboardChartData = {{ \Illuminate\Support\Js::from([
            'appointments' => $dashboard['appointment_statistics']['items'],
            'revenue' => $erp['trend'],
        ]) }};
    </script>
    @vite('resources/js/admin-dashboard.js')
@endsection
