@extends('layouts.app')
@section('title','Duty Rosters')
@section('breadcrumb')<li class="breadcrumb-item active">Duty Rosters</li>@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="page-title mb-0">Duty Rosters</h5>
    <a href="{{ route('roster.duty.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> New</a>
</div>
<form class="row g-2 mb-3" method="GET">
    <div class="col-md-4">
        <select name="department_id" class="form-select form-select-sm">
            <option value="">All Departments</option>
            @foreach($departments as $d)<option value="{{ $d->id }}" {{ request('department_id')==$d->id?'selected':'' }}>{{ $d->name }}</option>@endforeach
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
</form>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light"><tr><th>#</th><th>Title</th><th>Department</th><th>From</th><th>To</th><th>Year</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @forelse($rosters as $r)
                <tr>
                    <td>{{ $rosters->firstItem()+$loop->index }}</td>
                    <td>{{ $r->title }}</td>
                    <td>{{ $r->department?->name }}</td>
                    <td>{{ $r->from_date }}</td>
                    <td>{{ $r->to_date }}</td>
                    <td>{{ $r->roster_year ?? '—' }}</td>
                    <td>@php $sc=['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$r->status]??'secondary'@endphp<span class="badge bg-{{ $sc }}">{{ ucfirst($r->status) }}</span></td>
                    <td class="text-end">
                        <a href="{{ route('roster.duty.show', $r) }}" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a>
                        <a href="{{ route('roster.duty.edit', $r) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" action="{{ route('roster.duty.destroy', $r) }}" class="d-inline" onsubmit="return confirm('Delete?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No duty rosters found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($rosters->hasPages())<div class="card-footer">{{ $rosters->links() }}</div>@endif
</div>
@endsection
