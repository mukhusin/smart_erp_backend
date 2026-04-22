@extends('layouts.app')
@section('title','Edit Asset')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('assets.index') }}">Assets</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-xl-9">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-pencil me-2 text-primary"></i>Edit — {{ $asset->name }}</div>
    <div class="card-body">
        <form method="POST" action="{{ route('assets.update',$asset) }}">
            @csrf @method('PUT')
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label required-star">Asset Name</label>
                    <input type="text" name="name" class="form-control" value="{{ old('name',$asset->name) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label required-star">Status</label>
                    <select name="status" class="form-select">
                        @foreach(['available','in-use','maintenance','disposed'] as $s)
                        <option value="{{ $s }}" {{ old('status',$asset->status)==$s?'selected':'' }}>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Category</label>
                    <select name="asset_category_id" class="form-select">
                        <option value="">— Select —</option>
                        @foreach($categories as $c)
                        <option value="{{ $c->id }}" {{ old('asset_category_id',$asset->asset_category_id)==$c->id?'selected':'' }}>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Location</label>
                    <select name="asset_location_id" class="form-select">
                        <option value="">— Select —</option>
                        @foreach($locations as $l)
                        <option value="{{ $l->id }}" {{ old('asset_location_id',$asset->asset_location_id)==$l->id?'selected':'' }}>{{ $l->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Department</label>
                    <select name="department_id" class="form-select">
                        <option value="">— Select —</option>
                        @foreach($departments as $d)
                        <option value="{{ $d->id }}" {{ old('department_id',$asset->department_id)==$d->id?'selected':'' }}>{{ $d->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Assigned Employee</label>
                    <select name="employee_id" class="form-select">
                        <option value="">— None —</option>
                        @foreach($employees as $e)
                        <option value="{{ $e->id }}" {{ old('employee_id',$asset->employee_id)==$e->id?'selected':'' }}>{{ $e->full_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label">Model</label><input type="text" name="model" class="form-control" value="{{ old('model',$asset->model) }}"></div>
                <div class="col-md-3"><label class="form-label">Manufacturer</label><input type="text" name="manufacturer" class="form-control" value="{{ old('manufacturer',$asset->manufacturer) }}"></div>
                <div class="col-md-4"><label class="form-label">Serial Number</label><input type="text" name="serial_number" class="form-control" value="{{ old('serial_number',$asset->serial_number) }}"></div>
                <div class="col-md-4"><label class="form-label">Barcode</label><input type="text" name="barcode" class="form-control" value="{{ old('barcode',$asset->barcode) }}"></div>
                <div class="col-md-4"><label class="form-label">Cost</label><input type="number" name="cost" class="form-control" step="0.01" min="0" value="{{ old('cost',$asset->cost) }}"></div>
                <div class="col-md-4"><label class="form-label">Purchase Date</label><input type="date" name="purchase_date" class="form-control" value="{{ old('purchase_date',$asset->purchase_date?->format('Y-m-d')) }}"></div>
                <div class="col-md-4"><label class="form-label">Warranty Expiry</label><input type="date" name="warranty_expiration_date" class="form-control" value="{{ old('warranty_expiration_date',$asset->warranty_expiration_date?->format('Y-m-d')) }}"></div>
                <div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3">{{ old('description',$asset->description) }}</textarea></div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update</button>
                <a href="{{ route('assets.show',$asset) }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
