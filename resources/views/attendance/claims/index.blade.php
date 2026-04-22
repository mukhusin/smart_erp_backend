@extends('layouts.app')
@section('title','Attendance Claims')
@section('breadcrumb')<li class="breadcrumb-item active">Attendance Claims</li>@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="page-title mb-0">Attendance Claims</h5>
    <a href="{{ route('attendance.claims.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> New Claim</a>
</div>
<form class="row g-2 mb-3" method="GET">
    <div class="col-md-3">
        <select name="status" class="form-select form-select-sm">
            <option value="">All Status</option>
            @foreach(['pending','approved','rejected'] as $s)<option value="{{ $s }}" {{ request('status')==$s?'selected':'' }}>{{ ucfirst($s) }}</option>@endforeach
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
</form>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light"><tr><th>#</th><th>Employee</th><th>Date</th><th>Reason</th><th>Lost Hrs</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @forelse($claims as $c)
                <tr>
                    <td>{{ $claims->firstItem()+$loop->index }}</td>
                    <td>{{ $c->employee?->full_name }}</td>
                    <td>{{ $c->claim_date }}</td>
                    <td>{{ $c->reason?->reason }}</td>
                    <td>{{ $c->lost_hour }}</td>
                    <td>@php $sc=['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$c->claim_status]??'secondary'@endphp<span class="badge bg-{{ $sc }}">{{ ucfirst($c->claim_status) }}</span></td>
                    <td class="text-end">
                        <a href="{{ route('attendance.claims.show', $c) }}" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a>
                        <a href="{{ route('attendance.claims.edit', $c) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" action="{{ route('attendance.claims.destroy', $c) }}" class="d-inline" onsubmit="return confirm('Delete?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No claims found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($claims->hasPages())<div class="card-footer">{{ $claims->links() }}</div>@endif
</div>
@endsection
