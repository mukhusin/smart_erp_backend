@extends('layouts.app')
@section('title','Edit Item')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('inventory.items.index') }}">Items</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-8">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-pencil me-2 text-primary"></i>Edit — {{ $item->name }}</div>
    <div class="card-body">
        <form method="POST" action="{{ route('inventory.items.update', $item) }}">
            @csrf @method('PUT')
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Item Code</label>
                    <input type="text" name="code" class="form-control" value="{{ old('code', $item->code) }}">
                </div>
                <div class="col-md-8">
                    <label class="form-label required-star">Item Name</label>
                    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $item->name) }}">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Category</label>
                    <select name="inventory_category_id" class="form-select">
                        <option value="">— None —</option>
                        @foreach($categories as $cat)<option value="{{ $cat->id }}" {{ old('inventory_category_id',$item->inventory_category_id)==$cat->id?'selected':'' }}>{{ $cat->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Unit</label>
                    <input type="text" name="unit" class="form-control" value="{{ old('unit', $item->unit) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label required-star">Unit Cost</label>
                    <input type="number" name="unit_cost" class="form-control @error('unit_cost') is-invalid @enderror" step="0.01" min="0" value="{{ old('unit_cost', $item->unit_cost) }}">
                    @error('unit_cost')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label text-muted">Quantity on Hand</label>
                    <input type="number" class="form-control bg-light" value="{{ $item->quantity_on_hand }}" disabled>
                    <small class="text-muted">Use Stock Movements to adjust quantity.</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label required-star">Minimum Stock Level</label>
                    <input type="number" name="minimum_stock" class="form-control @error('minimum_stock') is-invalid @enderror" step="0.01" min="0" value="{{ old('minimum_stock', $item->minimum_stock) }}">
                    @error('minimum_stock')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="is_active" id="isActive" value="1" {{ old('is_active', $item->is_active) ? 'checked' : '' }}>
                        <label class="form-check-label" for="isActive">Active</label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3">{{ old('description', $item->description) }}</textarea>
                </div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update</button>
                <a href="{{ route('inventory.items.show', $item) }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div></div>
@endsection
