@extends('layouts.app')
@section('title','Roster Dashboard')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Roster & Schedule</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-calendar3-week me-2" style="color:#20c997"></i>Roster & Schedule</h4>
        <p class="text-muted small mb-0">{{ $currentCompany?->name }}</p>
    </div>
</div>

<div class="row g-3">
    @php
        $rosterLinks = [
            ['title' => 'Duty Roster',          'desc' => 'Assign and view employee duty schedules', 'icon' => 'bi-calendar-week',    'color' => '#20c997', 'light' => '#e0f8f1', 'route' => 'roster.duty.index'],
            ['title' => 'Timetables',           'desc' => 'Manage shift timetable configurations',   'icon' => 'bi-clock-history',    'color' => '#0d6efd', 'light' => '#e7f0ff', 'route' => 'roster.timetables.index'],
            ['title' => 'Annual Leave Roster',  'desc' => 'Schedule and approve annual leave rosters','icon' => 'bi-calendar2-heart',  'color' => '#ffc107', 'light' => '#fff8e1', 'route' => 'roster.annual-leave.index'],
            ['title' => 'Roster Codes',         'desc' => 'Configure roster shift codes',             'icon' => 'bi-tag',              'color' => '#6c757d', 'light' => '#f0f0f0', 'route' => 'roster.codes.index'],
        ];
    @endphp

    @foreach($rosterLinks as $r)
    <div class="col-md-6 col-xl-3">
        <a href="{{ route($r['route']) }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 p-3" style="border-left:4px solid {{ $r['color'] }} !important; border-radius:12px">
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
