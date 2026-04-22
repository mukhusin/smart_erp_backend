@extends('layouts.app')
@section('title','Edit Asset Category')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('assets.categories.index') }}">Categories</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-6">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-pencil me-2 text-primary"></i>Edit — {{ $category->name }}</div>
    <div class="card-body">
        <form method="POST" action="{{ route('assets.categories.update',$category) }}">
            @csrf @method('PUT')
            <div class="mb-3">
                <label class="form-label required-star">Name</label>
                <input type="text" name="name" class="form-control" value="{{ old('name',$category->name) }}">
            </div>
            <div class="mb-4">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="3">{{ old('description',$category->description) }}</textarea>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update</button>
                <a href="{{ route('assets.categories.index') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div></div>
@endsection
