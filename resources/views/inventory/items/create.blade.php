@extends('layouts.app')
@section('title','Add Inventory Item')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('inventory.items.index') }}">Items</a></li>
    <li class="breadcrumb-item active">Add</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-8">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-box-seam me-2 text-primary"></i>Add Inventory Item</div>
    <div class="card-body">
        <form method="POST" action="{{ route('inventory.items.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Item Code</label>
                    <input type="text" name="code" class="form-control" value="{{ old('code') }}" placeholder="e.g. ITM-001">
                </div>
                <div class="col-md-8">
                    <label class="form-label required-star">Item Name</label>
                    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Category</label>
                    <select name="inventory_category_id" class="form-select">
                        <option value="">— None —</option>
                        @foreach($categories as $cat)<option value="{{ $cat->id }}" {{ old('inventory_category_id')==$cat->id?'selected':'' }}>{{ $cat->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Unit</label>
                    <input type="text" name="unit" class="form-control" value="{{ old('unit') }}" placeholder="pcs / kg / litre">
                </div>
                <div class="col-md-3">
                    <label class="form-label required-star">Unit Cost</label>
                    <input type="number" name="unit_cost" class="form-control @error('unit_cost') is-invalid @enderror" step="0.01" min="0" value="{{ old('unit_cost', 0) }}">
                    @error('unit_cost')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label required-star">Opening Quantity</label>
                    <input type="number" name="quantity_on_hand" class="form-control @error('quantity_on_hand') is-invalid @enderror" step="0.01" min="0" value="{{ old('quantity_on_hand', 0) }}">
                    @error('quantity_on_hand')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label required-star">Minimum Stock Level</label>
                    <input type="number" name="minimum_stock" class="form-control @error('minimum_stock') is-invalid @enderror" step="0.01" min="0" value="{{ old('minimum_stock', 0) }}">
                    @error('minimum_stock')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3">{{ old('description') }}</textarea>
                </div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button>
                <a href="{{ route('inventory.items.index') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div></div>
@endsection
