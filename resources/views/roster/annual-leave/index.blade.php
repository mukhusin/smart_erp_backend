@extends('layouts.app')
@section('title','Annual Leave Roster')

@section('breadcrumb')
    <li class="breadcrumb-item active">Annual Leave Roster</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="page-title mb-0"><i class="bi bi-calendar2-heart me-2 text-primary"></i>Annual Leave Roster</h5>
    <a href="{{ route('roster.annual-leave.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i> New Entry
    </a>
</div>

{{-- Quick status tabs --}}
<div class="d-flex gap-2 mb-3 flex-wrap">
    @foreach(['' => 'All', 'pending' => 'Pending', 'hod_approved' => 'Awaiting HR ⭐', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $val => $label)
        <a href="{{ route('roster.annual-leave.index', array_merge(request()->except('status','page'), ['status' => $val])) }}"
           class="btn btn-sm {{ request('status', '') === $val ? 'btn-primary' : 'btn-outline-secondary' }}">
            {{ $label }}
        </a>
    @endforeach
</div>

<form class="row g-2 mb-3" method="GET">
    <input type="hidden" name="status" value="{{ request('status') }}">
    <div class="col-md-3">
        <input type="text" name="leave_year" class="form-control form-control-sm"
               placeholder="Year..." value="{{ request('leave_year') }}">
    </div>
    <div class="col-md-3">
        <select name="department_id" class="form-select form-select-sm">
            <option value="">All Departments</option>
            @foreach($departments as $dept)
                <option value="{{ $dept->id }}" {{ request('department_id') == $dept->id ? 'selected' : '' }}>{{ $dept->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
    @if(request()->hasAny(['leave_year','department_id']))
        <div class="col-auto"><a href="{{ route('roster.annual-leave.index', ['status' => request('status')]) }}" class="btn btn-sm btn-outline-danger">Clear</a></div>
    @endif
</form>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Days</th>
                    <th>Year</th>
                    <th>HOD</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($annualLeaves as $al)
                <tr>
                    <td class="fw-medium">{{ $al->employee?->full_name }}</td>
                    <td class="small text-muted">{{ $al->employee?->department?->name ?? '—' }}</td>
                    <td>{{ \Carbon\Carbon::parse($al->start_date)->format('d M Y') }}</td>
                    <td>{{ \Carbon\Carbon::parse($al->end_date)->format('d M Y') }}</td>
                    <td>{{ $al->days }}</td>
                    <td>{{ $al->leave_year ?? '—' }}</td>
                    <td>
                        @if($al->hod_is_approved)
                            <span class="badge bg-success-subtle text-success">Approved</span>
                        @elseif($al->hod_is_approved === false && $al->hod_id)
                            <span class="badge bg-danger-subtle text-danger">Rejected</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary">Pending</span>
                        @endif
                    </td>
                    <td>
                        @php $cls = match($al->status ?? 'pending') {
                            'approved'      => 'bg-success',
                            'pending'       => 'bg-warning text-dark',
                            'hod_approved'  => 'bg-info',
                            'rejected'      => 'bg-danger',
                            default         => 'bg-secondary',
                        }; @endphp
                        <span class="badge {{ $cls }}">{{ ucfirst(str_replace('_', ' ', $al->status ?? 'pending')) }}</span>
                    </td>
                    <td class="text-end">
                        <a href="{{ route('roster.annual-leave.show', $al) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                        @if(in_array($al->status ?? '', ['pending','hod_approved']))
                            <form method="POST" action="{{ route('roster.annual-leave.approve', $al) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-sm btn-success" title="HR Approve"
                                        onclick="return confirm('Approve this annual leave?')">
                                    <i class="bi bi-check-lg"></i>
                                </button>
                            </form>
                        @endif
                        <a href="{{ route('roster.annual-leave.edit', $al) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" action="{{ route('roster.annual-leave.destroy', $al) }}" class="d-inline" onsubmit="return confirm('Delete?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No entries found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($annualLeaves->hasPages())<div class="card-footer">{{ $annualLeaves->links() }}</div>@endif
</div>
@endsection
