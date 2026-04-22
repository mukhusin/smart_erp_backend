@extends('layouts.app')
@section('title','Add Fuel Record')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('fleet.fuel.index') }}">Fuel Records</a></li>
    <li class="breadcrumb-item active">Add</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-7">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-fuel-pump me-2 text-success"></i>Add Fuel Record</div>
    <div class="card-body">
        <form method="POST" action="{{ route('fleet.fuel.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-8"><label class="form-label required-star">Vehicle</label><select name="vehicle_id" class="form-select @error('vehicle_id') is-invalid @enderror"><option value="">— Select vehicle —</option>@foreach($vehicles as $v)<option value="{{ $v->id }}" {{ old('vehicle_id',$vehicle?->id)==$v->id?'selected':'' }}>{{ $v->plate_number }} — {{ $v->make }} {{ $v->model }}</option>@endforeach</select>@error('vehicle_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-md-4"><label class="form-label required-star">Date</label><input type="date" name="date" class="form-control" value="{{ old('date', date('Y-m-d')) }}"></div>
                <div class="col-md-4"><label class="form-label required-star">Liters</label><input type="number" name="liters" id="liters" class="form-control" step="0.01" min="0.01" value="{{ old('liters') }}"></div>
                <div class="col-md-4"><label class="form-label">Cost per Liter</label><input type="number" name="cost_per_liter" id="costPerLiter" class="form-control" step="0.01" min="0" value="{{ old('cost_per_liter') }}"></div>
                <div class="col-md-4"><label class="form-label">Total Cost</label><input type="number" name="total_cost" id="totalCost" class="form-control" step="0.01" min="0" value="{{ old('total_cost') }}"></div>
                <div class="col-md-6"><label class="form-label">Odometer (km)</label><input type="number" name="odometer_reading" class="form-control" min="0" value="{{ old('odometer_reading') }}"></div>
                <div class="col-md-6"><label class="form-label">Filled By</label><select name="filled_by" class="form-select"><option value="">— Select —</option>@foreach($employees as $e)<option value="{{ $e->id }}" {{ old('filled_by')==$e->id?'selected':'' }}>{{ $e->full_name }}</option>@endforeach</select></div>
                <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea></div>
            </div>
            <div class="d-flex gap-2 mt-4"><button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Save</button><a href="{{ route('fleet.fuel.index') }}" class="btn btn-light">Cancel</a></div>
        </form>
    </div>
</div>
</div></div>
@endsection
@push('scripts')
<script>
function calcTotal() {
    const l = parseFloat(document.getElementById('liters').value)||0;
    const c = parseFloat(document.getElementById('costPerLiter').value)||0;
    if (l && c) document.getElementById('totalCost').value = (l*c).toFixed(2);
}
document.getElementById('liters').addEventListener('input', calcTotal);
document.getElementById('costPerLiter').addEventListener('input', calcTotal);
</script>
@endpush
