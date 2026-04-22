@extends('layouts.app')
@section('title','Fleet')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Fleet</li>
@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div><h5 class="page-title">Fleet Management</h5><p class="page-subtitle">Manage company vehicles</p></div>
    <a href="{{ route('fleet.vehicles.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Vehicle</a>
</div>
@php $statusColors = ['available'=>'success','in-use'=>'primary','maintenance'=>'warning','disposed'=>'secondary']; @endphp
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label small mb-1">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option>@foreach(array_keys($statusColors) as $st)<option value="{{ $st }}" {{ request('status')==$st?'selected':'' }}>{{ ucfirst($st) }}</option>@endforeach</select></div>
            <div class="col-md-2"><button type="submit" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button></div>
        </form>
    </div>
</div>
<div class="row g-3">
@forelse($vehicles as $vehicle)
<div class="col-md-6 col-xl-4">
    <div class="card h-100">
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="fw-bold fs-5">{{ $vehicle->plate_number }}</span>
                <span class="badge bg-{{ $statusColors[$vehicle->status]??'secondary' }}">{{ ucfirst($vehicle->status) }}</span>
            </div>
            <p class="mb-1 text-muted small">{{ $vehicle->make }} {{ $vehicle->model }} {{ $vehicle->year ? '('.$vehicle->year.')':'' }}</p>
            <p class="mb-2 text-muted small"><i class="bi bi-car-front me-1"></i>{{ ucfirst($vehicle->type) }} {{ $vehicle->color ? '· '.$vehicle->color:'' }}</p>
            @if($vehicle->currentDriver)
            <p class="mb-0 small"><i class="bi bi-person me-1 text-primary"></i>{{ $vehicle->currentDriver->full_name }}</p>
            @endif
        </div>
        <div class="card-footer bg-white d-flex gap-2">
            <a href="{{ route('fleet.vehicles.show',$vehicle) }}" class="btn btn-sm btn-outline-primary flex-fill"><i class="bi bi-eye me-1"></i>View</a>
            <a href="{{ route('fleet.vehicles.edit',$vehicle) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
            <form method="POST" action="{{ route('fleet.vehicles.destroy',$vehicle) }}" onsubmit="return confirm('Delete vehicle?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
        </div>
    </div>
</div>
@empty
<div class="col-12"><div class="card"><div class="card-body text-center text-muted py-5"><i class="bi bi-car-front fs-1 d-block mb-2 opacity-25"></i>No vehicles. <a href="{{ route('fleet.vehicles.create') }}">Add one</a>.</div></div></div>
@endforelse
</div>
@if($vehicles->hasPages())
<div class="mt-3">{{ $vehicles->links() }}</div>
@endif
@endsection
