@extends('layouts.app')
@section('title','Maintenance Log Detail')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('fleet.maintenance.index') }}">Maintenance</a></li>
    <li class="breadcrumb-item active">Detail</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-6">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-tools me-2 text-warning"></i>Maintenance Log Detail</div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4 text-muted">Vehicle</dt>
            <dd class="col-sm-8">
                <a href="{{ route('fleet.vehicles.show', $maintenance->vehicle) }}" class="fw-semibold text-decoration-none">
                    {{ $maintenance->vehicle?->plate_number }}
                </a>
                <span class="text-muted ms-1">{{ $maintenance->vehicle?->make }} {{ $maintenance->vehicle?->model }}</span>
            </dd>

            <dt class="col-sm-4 text-muted">Date</dt>
            <dd class="col-sm-8">{{ $maintenance->date?->format('d M Y') }}</dd>

            <dt class="col-sm-4 text-muted">Type</dt>
            <dd class="col-sm-8"><span class="badge bg-secondary-subtle text-secondary">{{ ucfirst($maintenance->type) }}</span></dd>

            <dt class="col-sm-4 text-muted">Description</dt>
            <dd class="col-sm-8">{{ $maintenance->description ?? '—' }}</dd>

            <dt class="col-sm-4 text-muted">Service Provider</dt>
            <dd class="col-sm-8">{{ $maintenance->service_provider ?? '—' }}</dd>

            <dt class="col-sm-4 text-muted">Done By</dt>
            <dd class="col-sm-8">{{ $maintenance->doneBy?->full_name ?? '— External —' }}</dd>

            <dt class="col-sm-4 text-muted">Cost</dt>
            <dd class="col-sm-8 fw-semibold">{{ $maintenance->cost ? number_format($maintenance->cost, 2) : '—' }}</dd>

            <dt class="col-sm-4 text-muted">Next Service Date</dt>
            <dd class="col-sm-8">
                @if($maintenance->next_service_date)
                    <span class="{{ $maintenance->next_service_date->isPast() ? 'text-danger fw-semibold' : '' }}">
                        {{ $maintenance->next_service_date->format('d M Y') }}
                        @if($maintenance->next_service_date->isPast())
                            <span class="badge bg-danger ms-1">Overdue</span>
                        @elseif($maintenance->next_service_date->diffInDays(now()) <= 30)
                            <span class="badge bg-warning text-dark ms-1">Due Soon</span>
                        @endif
                    </span>
                @else
                    —
                @endif
            </dd>

            <dt class="col-sm-4 text-muted">Recorded At</dt>
            <dd class="col-sm-8">{{ $maintenance->created_at?->format('d M Y H:i') }}</dd>
        </dl>
    </div>
    <div class="card-footer bg-white d-flex gap-2">
        <a href="{{ route('fleet.maintenance.index') }}" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>Back</a>
        <a href="{{ route('fleet.vehicles.show', $maintenance->vehicle) }}" class="btn btn-outline-primary"><i class="bi bi-car-front me-1"></i>View Vehicle</a>
        <form method="POST" action="{{ route('fleet.maintenance.destroy', $maintenance) }}" class="ms-auto" onsubmit="return confirm('Delete this maintenance log?')">
            @csrf @method('DELETE')
            <button class="btn btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete</button>
        </form>
    </div>
</div>
</div></div>
@endsection
