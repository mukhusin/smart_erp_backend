@extends('layouts.app')
@section('title','Leave Dashboard')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Leave Management</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-calendar-x me-2 text-warning"></i>Leave Management</h4>
        <p class="text-muted small mb-0">{{ $currentCompany?->name }} &nbsp;·&nbsp; {{ now()->format('F Y') }}</p>
    </div>
    <a href="{{ route('leave.requests.create') }}" class="btn btn-warning btn-sm text-dark">
        <i class="bi bi-calendar-plus me-1"></i> New Request
    </a>
</div>

<div class="row g-3 mb-4">
    @php
        $cards = [
            ['label'=>'Pending Approval',   'value'=> $stats['pending'],   'icon'=>'bi-hourglass-split', 'color'=>'#ffc107', 'light'=>'#fff8e1'],
            ['label'=>'On Leave Now',        'value'=> $stats['on_leave'],  'icon'=>'bi-person-slash',   'color'=>'#0dcaf0', 'light'=>'#e0f9ff'],
            ['label'=>'Approved This Month', 'value'=> $stats['approved'],  'icon'=>'bi-calendar-check', 'color'=>'#198754', 'light'=>'#e6f4ee'],
            ['label'=>'Rejected This Month', 'value'=> $stats['rejected'],  'icon'=>'bi-calendar-x',     'color'=>'#dc3545', 'light'=>'#fde8ea'],
        ];
    @endphp
    @foreach($cards as $c)
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100 text-center py-3"
             style="border-top:3px solid {{ $c['color'] }} !important">
            <div class="mx-auto mb-2 rounded-circle d-flex align-items-center justify-content-center"
                 style="width:48px;height:48px;background:{{ $c['light'] }}">
                <i class="bi {{ $c['icon'] }}" style="color:{{ $c['color'] }};font-size:1.4rem"></i>
            </div>
            <div class="fw-bold fs-3" style="color:{{ $c['color'] }}">{{ $c['value'] }}</div>
            <div class="text-muted small">{{ $c['label'] }}</div>
        </div>
    </div>
    @endforeach
</div>

@if($byType->isNotEmpty())
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent fw-semibold">Leave Requests by Type — {{ now()->format('F Y') }}</div>
    <div class="card-body">
        @php $maxT = $byType->max('total') ?: 1; @endphp
        @foreach($byType as $row)
        <div class="d-flex align-items-center mb-2">
            <span class="small text-truncate me-2" style="min-width:130px">{{ $row->leaveType?->name ?? '—' }}</span>
            <div class="progress flex-grow-1 me-2" style="height:8px">
                <div class="progress-bar bg-warning" style="width:{{ round($row->total/$maxT*100) }}%"></div>
            </div>
            <span class="badge bg-light text-dark border small">{{ $row->total }}</span>
        </div>
        @endforeach
    </div>
</div>
@endif

<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent fw-semibold">Quick Actions</div>
    <div class="card-body d-flex flex-wrap gap-2">
        <a href="{{ route('leave.requests.index') }}" class="btn btn-outline-warning btn-sm"><i class="bi bi-calendar-minus me-1"></i>Leave Requests</a>
        <a href="{{ route('leave.requests.index') }}?status=pending" class="btn btn-outline-danger btn-sm"><i class="bi bi-hourglass-split me-1"></i>Pending ({{ $stats['pending'] }})</a>
        <a href="{{ route('leave.types.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list-check me-1"></i>Leave Types</a>
        <a href="{{ route('leave.absence.index') }}" class="btn btn-outline-info btn-sm"><i class="bi bi-person-dash me-1"></i>Absence</a>
        <a href="{{ route('leave.public-holidays.index') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-star me-1"></i>Public Holidays</a>
    </div>
</div>
@endsection
