@extends('layouts.app')
@section('title','Reports Dashboard')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Reports</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-bar-chart-line me-2" style="color:#20c997"></i>Reports</h4>
        <p class="text-muted small mb-0">{{ $currentCompany?->name }}</p>
    </div>
</div>

<div class="row g-3">
    @php
        $reportLinks = [
            ['title' => 'Employee Report',    'desc' => 'Full employee list with filters',        'icon' => 'bi-people',            'color' => '#0dcaf0', 'light' => '#e0f9ff', 'route' => 'reports.employees'],
            ['title' => 'Leave Report',       'desc' => 'Leave history and balances by employee', 'icon' => 'bi-calendar-x',        'color' => '#ffc107', 'light' => '#fff8e1', 'route' => 'reports.leaves'],
            ['title' => 'Contract Report',    'desc' => 'Active, expired and expiring contracts', 'icon' => 'bi-file-earmark-text', 'color' => '#0d6efd', 'light' => '#e7f0ff', 'route' => 'reports.contracts'],
            ['title' => 'Attendance Summary', 'desc' => 'Monthly attendance by department',       'icon' => 'bi-fingerprint',       'color' => '#198754', 'light' => '#e6f4ee', 'route' => 'reports.attendance'],
        ];
    @endphp

    @foreach($reportLinks as $r)
    <div class="col-md-6 col-xl-3">
        <a href="{{ route($r['route']) }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 p-3" style="border-left:4px solid {{ $r['color'] }} !important; border-radius:12px; transition:transform .15s,box-shadow .15s">
                <div class="d-flex align-items-center gap-3 mb-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:44px;height:44px;background:{{ $r['light'] }}">
                        <i class="bi {{ $r['icon'] }}" style="color:{{ $r['color'] }};font-size:1.3rem"></i>
                    </div>
                    <div class="fw-semibold text-dark">{{ $r['title'] }}</div>
                </div>
                <p class="text-muted small mb-0">{{ $r['desc'] }}</p>
            </div>
        </a>
    </div>
    @endforeach
</div>
@endsection
