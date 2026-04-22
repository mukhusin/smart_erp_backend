@extends('layouts.app')
@section('title','Fuel Records')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Fuel Records</li>
@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div><h5 class="page-title">Fuel Records</h5><p class="page-subtitle">Fleet fuel consumption log</p></div>
    <a href="{{ route('fleet.fuel.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Record</a>
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
            <thead class="table-light"><tr><th>Date</th><th>Vehicle</th><th class="text-end">Liters</th><th class="text-end">Cost/L</th><th class="text-end">Total Cost</th><th class="text-end">Odometer</th><th>Filled By</th><th></th></tr></thead>
            <tbody>
            @forelse($records as $rec)
            <tr>
                <td>{{ $rec->date?->format('d M Y') }}</td>
                <td>{{ $rec->vehicle?->plate_number }} <span class="text-muted small">{{ $rec->vehicle?->make }}</span></td>
                <td class="text-end">{{ $rec->liters }}L</td>
                <td class="text-end">{{ $rec->cost_per_liter ? number_format($rec->cost_per_liter,2) : '—' }}</td>
                <td class="text-end fw-semibold">{{ $rec->total_cost ? number_format($rec->total_cost,2) : '—' }}</td>
                <td class="text-end">{{ $rec->odometer_reading ? number_format($rec->odometer_reading).' km' : '—' }}</td>
                <td>{{ $rec->filledBy?->full_name ?? '—' }}</td>
                <td class="text-end"><form method="POST" action="{{ route('fleet.fuel.destroy',$rec) }}" onsubmit="return confirm('Delete?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form></td>
            </tr>
            @empty
            <tr><td colspan="8" class="text-center text-muted py-4">No fuel records.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($records->hasPages())<div class="card-footer bg-white">{{ $records->links() }}</div>@endif
</div>
@endsection
