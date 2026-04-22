@extends('layouts.app')
@section('title','Add Maintenance Log')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('fleet.maintenance.index') }}">Maintenance</a></li>
    <li class="breadcrumb-item active">Add Log</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-7">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-tools me-2 text-warning"></i>Add Maintenance Log</div>
    <div class="card-body">
        <form method="POST" action="{{ route('fleet.maintenance.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-8"><label class="form-label required-star">Vehicle</label><select name="vehicle_id" class="form-select @error('vehicle_id') is-invalid @enderror"><option value="">— Select —</option>@foreach($vehicles as $v)<option value="{{ $v->id }}" {{ old('vehicle_id',$vehicle?->id)==$v->id?'selected':'' }}>{{ $v->plate_number }} — {{ $v->make }} {{ $v->model }}</option>@endforeach</select>@error('vehicle_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-md-4"><label class="form-label required-star">Date</label><input type="date" name="date" class="form-control" value="{{ old('date', date('Y-m-d')) }}"></div>
                <div class="col-md-4"><label class="form-label required-star">Type</label><select name="type" class="form-select">@foreach(['routine','repair','inspection','tyre','battery','other'] as $t)<option value="{{ $t }}" {{ old('type','routine')==$t?'selected':'' }}>{{ ucfirst($t) }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label">Cost</label><input type="number" name="cost" class="form-control" step="0.01" min="0" value="{{ old('cost') }}"></div>
                <div class="col-md-4"><label class="form-label">Next Service Date</label><input type="date" name="next_service_date" class="form-control" value="{{ old('next_service_date') }}"></div>
                <div class="col-md-8"><label class="form-label">Service Provider</label><input type="text" name="service_provider" class="form-control" value="{{ old('service_provider') }}" placeholder="Garage / workshop name"></div>
                <div class="col-md-4"><label class="form-label">Done By (Employee)</label><select name="done_by" class="form-select"><option value="">— External —</option>@foreach($employees as $e)<option value="{{ $e->id }}" {{ old('done_by')==$e->id?'selected':'' }}>{{ $e->full_name }}</option>@endforeach</select></div>
                <div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3">{{ old('description') }}</textarea></div>
            </div>
            <div class="d-flex gap-2 mt-4"><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button><a href="{{ route('fleet.maintenance.index') }}" class="btn btn-light">Cancel</a></div>
        </form>
    </div>
</div>
</div></div>
@endsection
