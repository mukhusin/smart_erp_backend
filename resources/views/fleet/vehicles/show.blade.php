@extends('layouts.app')
@section('title',$vehicle->plate_number)
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('fleet.vehicles.index') }}">Fleet</a></li>
    <li class="breadcrumb-item active">{{ $vehicle->plate_number }}</li>
@endsection
@section('content')
@php $statusColors = ['available'=>'success','in-use'=>'primary','maintenance'=>'warning','disposed'=>'secondary']; @endphp
<div class="d-flex align-items-start justify-content-between mb-3">
    <div><h5 class="page-title">{{ $vehicle->plate_number }}</h5><p class="page-subtitle text-muted">{{ $vehicle->make }} {{ $vehicle->model }} {{ $vehicle->year ? '('.$vehicle->year.')':'' }}</p></div>
    <div class="d-flex gap-2">
        <a href="{{ route('fleet.fuel.create') }}?vehicle_id={{ $vehicle->id }}" class="btn btn-sm btn-outline-success"><i class="bi bi-fuel-pump me-1"></i>Add Fuel</a>
        <a href="{{ route('fleet.maintenance.create') }}?vehicle_id={{ $vehicle->id }}" class="btn btn-sm btn-outline-warning"><i class="bi bi-tools me-1"></i>Maintenance</a>
        <a href="{{ route('fleet.vehicles.edit',$vehicle) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white py-2 fw-semibold">Vehicle Details</div>
            <div class="card-body">
                <dl class="row small mb-0">
                    <dt class="col-5 text-muted">Status</dt><dd class="col-7"><span class="badge bg-{{ $statusColors[$vehicle->status]??'secondary' }}">{{ ucfirst($vehicle->status) }}</span></dd>
                    <dt class="col-5 text-muted">Type</dt><dd class="col-7">{{ ucfirst($vehicle->type) }}</dd>
                    <dt class="col-5 text-muted">Color</dt><dd class="col-7">{{ $vehicle->color ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Driver</dt><dd class="col-7">{{ $vehicle->currentDriver?->full_name ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Purchase Date</dt><dd class="col-7">{{ $vehicle->purchase_date?->format('d M Y') ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Cost</dt><dd class="col-7">{{ $vehicle->purchase_cost ? number_format($vehicle->purchase_cost,2) : '—' }}</dd>
                    <dt class="col-5 text-muted">Insurance</dt><dd class="col-7 {{ $vehicle->insurance_expiry?->isPast() ? 'text-danger':'' }}">{{ $vehicle->insurance_expiry?->format('d M Y') ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Inspection</dt><dd class="col-7 {{ $vehicle->inspection_expiry?->isPast() ? 'text-danger':'' }}">{{ $vehicle->inspection_expiry?->format('d M Y') ?? '—' }}</dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header bg-white py-2 fw-semibold">Recent Fuel Records</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Date</th><th class="text-end">Liters</th><th class="text-end">Cost</th><th class="text-end">Odometer</th></tr></thead>
                    <tbody>
                    @forelse($vehicle->fuelRecords->take(5) as $fuel)
                    <tr><td>{{ $fuel->date?->format('d M Y') }}</td><td class="text-end">{{ $fuel->liters }}L</td><td class="text-end">{{ $fuel->total_cost ? number_format($fuel->total_cost,2) : '—' }}</td><td class="text-end">{{ $fuel->odometer_reading ? number_format($fuel->odometer_reading).' km' : '—' }}</td></tr>
                    @empty
                    <tr><td colspan="4" class="text-muted text-center py-2">No fuel records.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-header bg-white py-2 fw-semibold">Maintenance History</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Date</th><th>Type</th><th>Provider</th><th class="text-end">Cost</th></tr></thead>
                    <tbody>
                    @forelse($vehicle->maintenanceLogs->take(5) as $log)
                    <tr><td>{{ $log->date?->format('d M Y') }}</td><td>{{ ucfirst($log->type) }}</td><td>{{ $log->service_provider ?? '—' }}</td><td class="text-end">{{ $log->cost ? number_format($log->cost,2) : '—' }}</td></tr>
                    @empty
                    <tr><td colspan="4" class="text-muted text-center py-2">No maintenance logs.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
