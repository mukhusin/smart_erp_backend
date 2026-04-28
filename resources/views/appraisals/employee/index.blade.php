@extends('layouts.app')
@section('title','Employee Appraisals')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Employee Appraisals</li>
@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title">Employee Appraisals</h5>
        <p class="page-subtitle">Manage individual employee appraisal instances</p>
    </div>
    <a href="{{ route('appraisals.employee.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> New Appraisal</a>
</div>

{{-- Filters --}}
<form class="row g-2 mb-3" method="GET">
    <div class="col-md-3">
        <input type="text" name="period" class="form-control form-control-sm" placeholder="Period (e.g. Q1 2026)" value="{{ request('period') }}">
    </div>
    <div class="col-md-3">
        <select name="status" class="form-select form-select-sm">
            <option value="">All Statuses</option>
            @foreach(['draft','self_assessed','hod_reviewed','completed'] as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst(str_replace('_',' ',$s)) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3">
        <select name="template" class="form-select form-select-sm">
            <option value="">All Templates</option>
            @foreach($templates as $t)
                <option value="{{ $t->id }}" @selected(request('template') == $t->id)>{{ $t->appraisal_title }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-sm btn-outline-primary">Filter</button>
        <a href="{{ route('appraisals.employee.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
    </div>
</form>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>Employee</th>
                    <th>Template</th>
                    <th>Period</th>
                    <th>Status</th>
                    <th class="text-end">Score</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($appraisals as $a)
            <tr>
                <td class="fw-semibold">{{ $a->employee->full_name ?? '—' }}</td>
                <td>{{ $a->departmentAppraisal->appraisal_title ?? '—' }}</td>
                <td>{{ $a->period }}</td>
                <td>
                    @php
                        $badge = match($a->status) {
                            'draft'        => 'secondary',
                            'self_assessed'=> 'info',
                            'hod_reviewed' => 'warning',
                            'completed'    => 'success',
                            default        => 'secondary',
                        };
                    @endphp
                    <span class="badge bg-{{ $badge }}-subtle text-{{ $badge }}">{{ ucfirst(str_replace('_',' ',$a->status)) }}</span>
                </td>
                <td class="text-end">
                    @if($a->total_score > 0)
                        <span class="fw-semibold">{{ number_format($a->total_score, 1) }}%</span>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ route('appraisals.employee.show', $a) }}">
                                <i class="bi bi-eye me-2"></i>View / Score</a></li>
                            @if($a->status === 'draft')
                            <li>
                                <form method="POST" action="{{ route('appraisals.employee.destroy', $a) }}" onsubmit="return confirm('Delete this appraisal?')">
                                    @csrf @method('DELETE')
                                    <button class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Delete</button>
                                </form>
                            </li>
                            @endif
                        </ul>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No appraisal records found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">{{ $appraisals->links() }}</div>
@endsection
