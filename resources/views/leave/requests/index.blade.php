@extends('layouts.app')
@section('title','Leave Requests')

@section('breadcrumb')
    <li class="breadcrumb-item active">Leave Requests</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="page-title mb-0">Leave Requests</h5>
    <a href="{{ route('leave.requests.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i> New Request
    </a>
</div>

<form class="row g-2 mb-3" method="GET">
    <div class="col-md-4">
        <input type="text" name="search" class="form-control form-control-sm"
               placeholder="Search employee..." value="{{ request('search') }}">
    </div>
    <div class="col-md-3">
        <select name="status" class="form-select form-select-sm">
            <option value="">All Status</option>
            @foreach(['pending','approved','rejected'] as $s)
                <option value="{{ $s }}" {{ request('status') == $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Employee</th>
                    <th>Leave Type</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Days</th>
                    <th>Year</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($leaves as $leave)
                <tr>
                    <td>{{ $leaves->firstItem() + $loop->index }}</td>
                    <td>{{ $leave->employee?->full_name }}</td>
                    <td>{{ $leave->leaveType?->name ?? '—' }}</td>
                    <td>{{ $leave->start_date }}</td>
                    <td>{{ $leave->end_date }}</td>
                    <td>{{ $leave->days }}</td>
                    <td>{{ $leave->leave_year }}</td>
                    <td>
                        @php $sc = ['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$leave->status] ?? 'secondary' @endphp
                        <span class="badge bg-{{ $sc }}">{{ ucfirst($leave->status) }}</span>
                    </td>
                    <td class="text-end">
                        <a href="{{ route('leave.requests.show', $leave) }}" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a>
                        <a href="{{ route('leave.requests.edit', $leave) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" action="{{ route('leave.requests.destroy', $leave) }}" class="d-inline"
                              onsubmit="return confirm('Delete this leave request?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No leave requests found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($leaves->hasPages())
    <div class="card-footer">{{ $leaves->links() }}</div>
    @endif
</div>
@endsection
