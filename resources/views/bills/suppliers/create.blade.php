@extends('layouts.app')
@section('title','New Supplier')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('bills.suppliers.index') }}">Suppliers</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-truck me-2 text-primary"></i>New Supplier</div>
    <div class="card-body">
        <form method="POST" action="{{ route('bills.suppliers.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label required-star">Supplier Name</label>
                    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Supplier Code</label>
                    <input type="text" name="code" class="form-control" value="{{ old('code') }}" placeholder="e.g. SUP-001">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" value="{{ old('phone') }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email') }}">
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tax Number (TIN)</label>
                    <input type="text" name="tax_number" class="form-control" value="{{ old('tax_number') }}">
                </div>
                <div class="col-12">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2">{{ old('address') }}</textarea>
                </div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button>
                <a href="{{ route('bills.suppliers.index') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
