@extends('layouts.app')
@section('title','Employees')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Employees</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title">Employees</h5>
        <p class="page-subtitle">{{ $employees->total() }} employee(s) found</p>
    </div>
    <a href="{{ route('hr.employees.create') }}" class="btn btn-primary">
        <i class="bi bi-person-plus-fill me-1"></i> Add Employee
    </a>
</div>

{{-- Filters --}}
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Search name, employee no, email..." value="{{ request('search') }}">
            </div>
            <div class="col-md-3">
                <select name="department_id" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    @foreach($departments as $d)
                        <option value="{{ $d->id }}" {{ request('department_id')==$d->id?'selected':'' }}>{{ $d->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="employment_status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    @foreach(['employed','probation','terminated','suspended','resigned'] as $st)
                        <option value="{{ $st }}" {{ request('employment_status')==$st?'selected':'' }}>{{ ucfirst($st) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto d-flex gap-2">
                <button class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Search</button>
                <a href="{{ route('hr.employees.index') }}" class="btn btn-sm btn-light">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>Employee</th>
                    <th>Emp No.</th>
                    <th>Department</th>
                    <th>Position</th>
                    <th>Emp Date</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($employees as $emp)
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <img src="{{ $emp->profile_image_url }}" class="rounded-circle flex-shrink-0" width="32" height="32" style="object-fit:cover">
                            <div>
                                <div class="fw-semibold">{{ $emp->full_name }}</div>
                                <small class="text-muted">{{ $emp->email ?? '—' }}</small>
                            </div>
                        </div>
                    </td>
                    <td><span class="font-monospace small">{{ $emp->employee_no }}</span></td>
                    <td>{{ $emp->department?->name ?? '—' }}</td>
                    <td>{{ $emp->jobPosition?->title ?? '—' }}</td>
                    <td>{{ $emp->employment_date?->format('d M Y') ?? '—' }}</td>
                    <td>
                        @php $statusColors = ['employed'=>'success','probation'=>'warning','terminated'=>'danger','suspended'=>'danger','resigned'=>'secondary']; @endphp
                        <span class="badge bg-{{ $statusColors[$emp->employment_status] ?? 'secondary' }}-subtle text-{{ $statusColors[$emp->employment_status] ?? 'secondary' }}">
                            {{ ucfirst($emp->employment_status) }}
                        </span>
                    </td>
                    <td class="text-end">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="{{ route('hr.employees.show',$emp) }}"><i class="bi bi-person-circle me-2"></i>View Profile</a></li>
                                <li><a class="dropdown-item" href="{{ route('hr.employees.edit',$emp) }}"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="POST" action="{{ route('hr.employees.destroy',$emp) }}" onsubmit="return confirm('Archive this employee?')">
                                        @csrf @method('DELETE')
                                        <button class="dropdown-item text-danger"><i class="bi bi-archive me-2"></i>Archive</button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-5">
                        <i class="bi bi-people display-5 d-block mb-2 opacity-25"></i>
                        No employees found. <a href="{{ route('hr.employees.create') }}">Add the first one</a>.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($employees->hasPages())
    <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
        <small class="text-muted">Showing {{ $employees->firstItem() }}–{{ $employees->lastItem() }} of {{ $employees->total() }}</small>
        {{ $employees->links() }}
    </div>
    @endif
</div>
@endsection
