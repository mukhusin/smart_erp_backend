@extends('layouts.app')
@section('title','Job Positions')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Job Positions</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title">Job Positions</h5>
        <p class="page-subtitle">Roles and positions within the organisation</p>
    </div>
    <a href="{{ route('hr.positions.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> Add Position
    </a>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
                <tr><th>#</th><th>Title</th><th>Department</th><th class="text-center">Employees</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($positions as $i => $pos)
                <tr>
                    <td>{{ $i+1 }}</td>
                    <td class="fw-semibold">{{ $pos->title }}</td>
                    <td>{{ $pos->department?->name ?? '—' }}</td>
                    <td class="text-center"><span class="badge bg-primary">{{ $pos->employees_count }}</span></td>
                    <td>
                        @if($pos->is_active)
                            <span class="badge bg-success-subtle text-success">Active</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route('hr.positions.edit',$pos) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                        <form method="POST" action="{{ route('hr.positions.destroy',$pos) }}" class="d-inline" onsubmit="return confirm('Delete?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No positions found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
