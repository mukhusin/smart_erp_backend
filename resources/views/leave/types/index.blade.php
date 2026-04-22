@extends('layouts.app')
@section('title','Leave Types')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('leave.types.index') }}">Leave</a></li>
    <li class="breadcrumb-item active">Types</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="page-title mb-0">Leave Types</h5>
    <a href="{{ route('leave.types.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i> New Type
    </a>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Code</th>
                    <th>Gender</th>
                    <th>Max Days</th>
                    <th>With Pay</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($types as $type)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $type->name }}</td>
                    <td><span class="badge bg-secondary">{{ $type->code }}</span></td>
                    <td class="text-capitalize">{{ $type->gender }}</td>
                    <td>{{ $type->max_days }}</td>
                    <td>
                        @if($type->with_pay)
                            <span class="badge bg-success-subtle text-success">Yes</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary">No</span>
                        @endif
                    </td>
                    <td>
                        @if($type->is_active)
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-secondary">Inactive</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route('leave.types.edit', $type) }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <form method="POST" action="{{ route('leave.types.destroy', $type) }}" class="d-inline"
                              onsubmit="return confirm('Delete this leave type?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No leave types defined yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($types->hasPages())
    <div class="card-footer">{{ $types->links() }}</div>
    @endif
</div>
@endsection
