@extends('layouts.app')
@section('title','Edit Vehicle')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('fleet.vehicles.index') }}">Fleet</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-xl-9">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-pencil me-2 text-primary"></i>Edit — {{ $vehicle->plate_number }}</div>
    <div class="card-body">
        <form method="POST" action="{{ route('fleet.vehicles.update',$vehicle) }}">
            @csrf @method('PUT')
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label required-star">Plate Number</label><input type="text" name="plate_number" class="form-control" value="{{ old('plate_number',$vehicle->plate_number) }}"></div>
                <div class="col-md-4"><label class="form-label required-star">Make</label><input type="text" name="make" class="form-control" value="{{ old('make',$vehicle->make) }}"></div>
                <div class="col-md-4"><label class="form-label required-star">Model</label><input type="text" name="model" class="form-control" value="{{ old('model',$vehicle->model) }}"></div>
                <div class="col-md-3"><label class="form-label">Year</label><input type="number" name="year" class="form-control" value="{{ old('year',$vehicle->year) }}" min="1900" max="2100"></div>
                <div class="col-md-3"><label class="form-label">Color</label><input type="text" name="color" class="form-control" value="{{ old('color',$vehicle->color) }}"></div>
                <div class="col-md-3"><label class="form-label">Type</label><select name="type" class="form-select">@foreach(['sedan','suv','truck','bus','van','motorcycle','other'] as $t)<option value="{{ $t }}" {{ old('type',$vehicle->type)==$t?'selected':'' }}>{{ ucfirst($t) }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label">Status</label><select name="status" class="form-select">@foreach(['available','in-use','maintenance','disposed'] as $s)<option value="{{ $s }}" {{ old('status',$vehicle->status)==$s?'selected':'' }}>{{ ucfirst($s) }}</option>@endforeach</select></div>
                <div class="col-md-6"><label class="form-label">Current Driver</label><select name="current_driver_id" class="form-select"><option value="">— None —</option>@foreach($employees as $e)<option value="{{ $e->id }}" {{ old('current_driver_id',$vehicle->current_driver_id)==$e->id?'selected':'' }}>{{ $e->full_name }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label">Purchase Date</label><input type="date" name="purchase_date" class="form-control" value="{{ old('purchase_date',$vehicle->purchase_date?->format('Y-m-d')) }}"></div>
                <div class="col-md-3"><label class="form-label">Purchase Cost</label><input type="number" name="purchase_cost" class="form-control" step="0.01" value="{{ old('purchase_cost',$vehicle->purchase_cost) }}"></div>
                <div class="col-md-3"><label class="form-label">Insurance Expiry</label><input type="date" name="insurance_expiry" class="form-control" value="{{ old('insurance_expiry',$vehicle->insurance_expiry?->format('Y-m-d')) }}"></div>
                <div class="col-md-3"><label class="form-label">Inspection Expiry</label><input type="date" name="inspection_expiry" class="form-control" value="{{ old('inspection_expiry',$vehicle->inspection_expiry?->format('Y-m-d')) }}"></div>
                <div class="col-12"><label class="form-label">Notes</label><textarea name="description" class="form-control" rows="2">{{ old('description',$vehicle->description) }}</textarea></div>
            </div>
            <div class="d-flex gap-2 mt-4"><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update</button><a href="{{ route('fleet.vehicles.show',$vehicle) }}" class="btn btn-light">Cancel</a></div>
        </form>
    </div>
</div>
</div></div>
@endsection
