@extends('layouts.app')
@section('title', $department->name)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hr.departments.index') }}">Departments</a></li>
    <li class="breadcrumb-item active">{{ $department->name }}</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title">{{ $department->name }}</h5>
        <p class="page-subtitle">{{ $department->description ?? 'Department overview' }}</p>
    </div>
    <a href="{{ route('hr.departments.edit', $department) }}" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-pencil me-1"></i>Edit
    </a>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card stat-card bg-primary text-white">
            <div class="stat-icon bg-white bg-opacity-25"><i class="bi bi-people-fill text-white"></i></div>
            <div class="stat-body">
                <div class="stat-value">{{ $department->employees->count() }}</div>
                <div class="stat-label text-white-50">Employees</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card bg-success text-white">
            <div class="stat-icon bg-white bg-opacity-25"><i class="bi bi-diagram-2-fill text-white"></i></div>
            <div class="stat-body">
                <div class="stat-value">{{ $department->sections->count() }}</div>
                <div class="stat-label text-white-50">Sections</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card bg-info text-white">
            <div class="stat-icon bg-white bg-opacity-25"><i class="bi bi-person-badge-fill text-white"></i></div>
            <div class="stat-body">
                <div class="stat-value">{{ $department->headOfDepartment?->employee?->full_name ?? '—' }}</div>
                <div class="stat-label text-white-50">Head of Department</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white fw-semibold py-3">
                <i class="bi bi-people me-2 text-primary"></i>Employees
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Name</th><th>Position</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                    @forelse($department->employees as $emp)
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <img src="{{ $emp->profile_image_url }}" class="rounded-circle" width="28" height="28" style="object-fit:cover">
                                    {{ $emp->full_name }}
                                </div>
                            </td>
                            <td>{{ $emp->jobPosition?->title ?? '—' }}</td>
                            <td><span class="badge bg-primary-subtle text-primary">{{ ucfirst($emp->employment_status) }}</span></td>
                            <td><a href="{{ route('hr.employees.show', $emp) }}" class="btn btn-xs btn-light btn-sm py-0"><i class="bi bi-eye"></i></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">No employees in this department.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white fw-semibold py-3">
                <i class="bi bi-diagram-3 me-2 text-primary"></i>Sections
            </div>
            <ul class="list-group list-group-flush">
                @forelse($department->sections as $sec)
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        {{ $sec->name }}
                        <span class="badge bg-secondary">{{ $sec->employees->count() }} emp</span>
                    </li>
                @empty
                    <li class="list-group-item text-muted">No sections.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
@endsection
