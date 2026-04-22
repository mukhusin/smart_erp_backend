@extends('layouts.app')
@section('title','Maintenance Logs')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Maintenance</li>
@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div><h5 class="page-title">Maintenance Logs</h5><p class="page-subtitle">Vehicle maintenance and repair history</p></div>
    <a href="{{ route('fleet.maintenance.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Log</a>
</div>
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4"><label class="form-label small mb-1">Vehicle</label><select name="vehicle_id" class="form-select form-select-sm"><option value="">All Vehicles</option>@foreach($vehicles as $v)<option value="{{ $v->id }}" {{ request('vehicle_id')==$v->id?'selected':'' }}>{{ $v->plate_number }} — {{ $v->make }} {{ $v->model }}</option>@endforeach</select></div>
            <div class="col-md-2"><button type="submit" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button></div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light"><tr><th>Date</th><th>Vehicle</th><th>Type</th><th>Description</th><th>Provider</th><th class="text-end">Cost</th><th>Next Service</th><th></th></tr></thead>
            <tbody>
            @forelse($logs as $log)
            <tr>
                <td>{{ $log->date?->format('d M Y') }}</td>
                <td>{{ $log->vehicle?->plate_number }}</td>
                <td><span class="badge bg-secondary-subtle text-secondary">{{ ucfirst($log->type) }}</span></td>
                <td>{{ Str::limit($log->description,40) ?? '—' }}</td>
                <td>{{ $log->service_provider ?? '—' }}</td>
                <td class="text-end">{{ $log->cost ? number_format($log->cost,2) : '—' }}</td>
                <td>{{ $log->next_service_date?->format('d M Y') ?? '—' }}</td>
                <td class="text-end"><form method="POST" action="{{ route('fleet.maintenance.destroy',$log) }}" onsubmit="return confirm('Delete?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form></td>
            </tr>
            @empty
            <tr><td colspan="8" class="text-center text-muted py-4">No maintenance logs.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($logs->hasPages())<div class="card-footer bg-white">{{ $logs->links() }}</div>@endif
</div>
@endsection
