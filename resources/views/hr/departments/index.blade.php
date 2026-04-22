@extends('layouts.app')
@section('title','Departments')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Departments</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title">Departments</h5>
        <p class="page-subtitle">Manage departments and sections</p>
    </div>
    <a href="{{ route('hr.departments.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> Add Department
    </a>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Short Name</th>
                    <th>Head of Dept</th>
                    <th class="text-center">Sections</th>
                    <th class="text-center">Employees</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($departments as $i => $dept)
                <tr>
                    <td>{{ $i+1 }}</td>
                    <td class="fw-semibold">{{ $dept->name }}</td>
                    <td>{{ $dept->short_name ?? '—' }}</td>
                    <td>{{ $dept->headOfDepartment?->employee?->full_name ?? '—' }}</td>
                    <td class="text-center"><span class="badge bg-secondary">{{ $dept->sections_count }}</span></td>
                    <td class="text-center"><span class="badge bg-primary">{{ $dept->employees_count }}</span></td>
                    <td>
                        @if($dept->is_active)
                            <span class="badge bg-success-subtle text-success">Active</span>
                        @else
                            <span class="badge bg-danger-subtle text-danger">Inactive</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="{{ route('hr.departments.show',$dept) }}"><i class="bi bi-eye me-2"></i>View</a></li>
                                <li><a class="dropdown-item" href="{{ route('hr.departments.edit',$dept) }}"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="POST" action="{{ route('hr.departments.destroy',$dept) }}"
                                          onsubmit="return confirm('Delete this department?')">
                                        @csrf @method('DELETE')
                                        <button class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Delete</button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No departments found. <a href="{{ route('hr.departments.create') }}">Add one</a>.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
