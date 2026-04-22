@extends('layouts.app')
@section('title','Absence Requests')
@section('breadcrumb')
    <li class="breadcrumb-item active">Absence Requests</li>
@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="page-title mb-0">Absence Requests</h5>
    <a href="{{ route('leave.absence.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> New</a>
</div>
<form class="row g-2 mb-3" method="GET">
    <div class="col-md-4"><input type="text" name="search" class="form-control form-control-sm" placeholder="Search employee..." value="{{ request('search') }}"></div>
    <div class="col-md-3">
        <select name="status" class="form-select form-select-sm">
            <option value="">All Status</option>
            @foreach(['pending','approved','rejected'] as $s)<option value="{{ $s }}" {{ request('status') == $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>@endforeach
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
</form>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>#</th><th>Employee</th><th>From</th><th>To</th><th>Days</th><th>Has Per Diem</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                @forelse($absences as $abs)
                <tr>
                    <td>{{ $absences->firstItem() + $loop->index }}</td>
                    <td>{{ $abs->employee?->full_name }}</td>
                    <td>{{ $abs->from_date?->format('Y-m-d') }}</td>
                    <td>{{ $abs->to_date?->format('Y-m-d') }}</td>
                    <td>{{ $abs->number_of_days }}</td>
                    <td>{{ $abs->has_per_diem ? 'Yes' : 'No' }}</td>
                    <td>@php $sc=['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$abs->status]??'secondary' @endphp
                        <span class="badge bg-{{ $sc }}">{{ ucfirst($abs->status) }}</span></td>
                    <td class="text-end">
                        <a href="{{ route('leave.absence.show', $abs) }}" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a>
                        <a href="{{ route('leave.absence.edit', $abs) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" action="{{ route('leave.absence.destroy', $abs) }}" class="d-inline" onsubmit="return confirm('Delete?')">@csrf @method('DELETE')
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No absence requests.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($absences->hasPages())<div class="card-footer">{{ $absences->links() }}</div>@endif
</div>
@endsection
