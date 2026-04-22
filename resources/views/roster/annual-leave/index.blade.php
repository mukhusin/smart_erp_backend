@extends('layouts.app')
@section('title','Annual Leave Roster')
@section('breadcrumb')<li class="breadcrumb-item active">Annual Leave Roster</li>@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="page-title mb-0">Annual Leave Roster</h5>
    <a href="{{ route('roster.annual-leave.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> New Entry</a>
</div>
<form class="row g-2 mb-3" method="GET">
    <div class="col-md-3"><input type="text" name="leave_year" class="form-control form-control-sm" placeholder="Year..." value="{{ request('leave_year') }}"></div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
</form>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light"><tr><th>#</th><th>Employee</th><th>From</th><th>To</th><th>Days</th><th>Year</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @forelse($annualLeaves as $al)
                <tr>
                    <td>{{ $annualLeaves->firstItem()+$loop->index }}</td>
                    <td>{{ $al->employee?->full_name }}</td>
                    <td>{{ $al->start_date }}</td>
                    <td>{{ $al->end_date }}</td>
                    <td>{{ $al->days }}</td>
                    <td>{{ $al->leave_year ?? '—' }}</td>
                    <td><span class="badge bg-secondary">{{ str_replace('_',' ',ucfirst($al->status)) }}</span></td>
                    <td class="text-end">
                        <a href="{{ route('roster.annual-leave.show', $al) }}" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a>
                        <a href="{{ route('roster.annual-leave.edit', $al) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" action="{{ route('roster.annual-leave.destroy', $al) }}" class="d-inline" onsubmit="return confirm('Delete?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No entries found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($annualLeaves->hasPages())<div class="card-footer">{{ $annualLeaves->links() }}</div>@endif
</div>
@endsection
