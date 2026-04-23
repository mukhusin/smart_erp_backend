@extends('layouts.app')
@section('title','Fuel Record Detail')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('fleet.fuel.index') }}">Fuel Records</a></li>
    <li class="breadcrumb-item active">Detail</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-6">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-fuel-pump me-2 text-success"></i>Fuel Record Detail</div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4 text-muted">Vehicle</dt>
            <dd class="col-sm-8">
                <a href="{{ route('fleet.vehicles.show', $fuel->vehicle) }}" class="fw-semibold text-decoration-none">
                    {{ $fuel->vehicle?->plate_number }}
                </a>
                <span class="text-muted ms-1">{{ $fuel->vehicle?->make }} {{ $fuel->vehicle?->model }}</span>
            </dd>

            <dt class="col-sm-4 text-muted">Date</dt>
            <dd class="col-sm-8">{{ $fuel->date?->format('d M Y') }}</dd>

            <dt class="col-sm-4 text-muted">Liters</dt>
            <dd class="col-sm-8 fw-semibold">{{ $fuel->liters }} L</dd>

            <dt class="col-sm-4 text-muted">Cost per Liter</dt>
            <dd class="col-sm-8">{{ $fuel->cost_per_liter ? number_format($fuel->cost_per_liter, 2) : '—' }}</dd>

            <dt class="col-sm-4 text-muted">Total Cost</dt>
            <dd class="col-sm-8 fw-semibold text-success fs-5">{{ $fuel->total_cost ? number_format($fuel->total_cost, 2) : '—' }}</dd>

            <dt class="col-sm-4 text-muted">Odometer</dt>
            <dd class="col-sm-8">{{ $fuel->odometer_reading ? number_format($fuel->odometer_reading).' km' : '—' }}</dd>

            <dt class="col-sm-4 text-muted">Filled By</dt>
            <dd class="col-sm-8">{{ $fuel->filledBy?->full_name ?? '—' }}</dd>

            <dt class="col-sm-4 text-muted">Notes</dt>
            <dd class="col-sm-8">{{ $fuel->notes ?? '—' }}</dd>

            <dt class="col-sm-4 text-muted">Recorded At</dt>
            <dd class="col-sm-8">{{ $fuel->created_at?->format('d M Y H:i') }}</dd>
        </dl>
    </div>
    <div class="card-footer bg-white d-flex gap-2">
        <a href="{{ route('fleet.fuel.index') }}" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>Back</a>
        <a href="{{ route('fleet.vehicles.show', $fuel->vehicle) }}" class="btn btn-outline-primary"><i class="bi bi-car-front me-1"></i>View Vehicle</a>
        <form method="POST" action="{{ route('fleet.fuel.destroy', $fuel) }}" class="ms-auto" onsubmit="return confirm('Delete this fuel record?')">
            @csrf @method('DELETE')
            <button class="btn btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete</button>
        </form>
    </div>
</div>
</div></div>
@endsection
