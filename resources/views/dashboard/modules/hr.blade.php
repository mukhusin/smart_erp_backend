@extends('layouts.app')
@section('title','HR Dashboard')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Human Resources</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-people-fill me-2 text-info"></i>Human Resources</h4>
        <p class="text-muted small mb-0">{{ $currentCompany?->name }} &nbsp;·&nbsp; {{ now()->format('F Y') }}</p>
    </div>
    <a href="{{ route('hr.employees.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-person-plus me-1"></i> Add Employee
    </a>
</div>

{{-- KPI Cards --}}
<div class="row g-3 mb-4">
    @php
        $cards = [
            ['label'=>'Active Employees',     'value'=> $stats['active_employees'],   'icon'=>'bi-people-fill',       'color'=>'#0dcaf0', 'light'=>'#e0f9ff'],
            ['label'=>'New Hires This Month', 'value'=> $stats['new_hires'],          'icon'=>'bi-person-plus-fill',  'color'=>'#198754', 'light'=>'#e6f4ee'],
            ['label'=>'Departments',          'value'=> $stats['total_departments'],  'icon'=>'bi-building',          'color'=>'#0d6efd', 'light'=>'#e7f0ff'],
            ['label'=>'Expiring Contracts',   'value'=> $stats['expiring_contracts'], 'icon'=>'bi-file-earmark-x',   'color'=>'#dc3545', 'light'=>'#fde8ea'],
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

<div class="row g-3 mb-4">
    {{-- Gender breakdown --}}
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold border-bottom-0 pb-0">
                <i class="bi bi-gender-ambiguous me-1"></i> Gender Breakdown
            </div>
            <div class="card-body pt-2">
                @php $total = $byGender->sum('total'); @endphp
                @foreach(['male','female'] as $g)
                    @php $row = $byGender->get($g); $count = $row?->total ?? 0; $pct = $total ? round($count/$total*100) : 0; @endphp
                    <div class="mb-2">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-capitalize">{{ $g }}</span>
                            <span>{{ $count }} ({{ $pct }}%)</span>
                        </div>
                        <div class="progress" style="height:8px">
                            <div class="progress-bar {{ $g === 'male' ? 'bg-info' : 'bg-pink' }}"
                                 style="width:{{ $pct }}%;background:{{ $g==='male'?'#0dcaf0':'#e91e8c' }}"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Departments --}}
    <div class="col-md-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold border-bottom-0 pb-0">
                <i class="bi bi-building me-1"></i> Employees by Department
            </div>
            <div class="card-body pt-2">
                @forelse($byDept as $dept)
                <div class="d-flex align-items-center mb-2">
                    <span class="flex-grow-1 small text-truncate" style="max-width:200px">{{ $dept->name }}</span>
                    <div class="progress flex-grow-1 mx-2" style="height:8px">
                        @php $mx = $byDept->max('employees_count') ?: 1; @endphp
                        <div class="progress-bar bg-info" style="width:{{ round($dept->employees_count/$mx*100) }}%"></div>
                    </div>
                    <span class="badge bg-light text-dark border small">{{ $dept->employees_count }}</span>
                </div>
                @empty
                    <p class="text-muted small">No departments found.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>

{{-- Quick Actions --}}
<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent fw-semibold">Quick Actions</div>
    <div class="card-body d-flex flex-wrap gap-2">
        <a href="{{ route('hr.employees.index') }}" class="btn btn-outline-info btn-sm"><i class="bi bi-people me-1"></i>Employees</a>
        <a href="{{ route('hr.departments.index') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-building me-1"></i>Departments</a>
        <a href="{{ route('hr.positions.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-person-badge me-1"></i>Positions</a>
        <a href="{{ route('hr.salary-scales.index') }}" class="btn btn-outline-success btn-sm"><i class="bi bi-bar-chart-steps me-1"></i>Salary Scales</a>
        <a href="{{ route('hr.contract-types.index') }}" class="btn btn-outline-warning btn-sm"><i class="bi bi-file-earmark-text me-1"></i>Contract Types</a>
    </div>
</div>
@endsection
