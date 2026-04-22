@extends('layouts.app')
@section('title','New Location')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('assets.locations.index') }}">Locations</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-6">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-geo-alt me-2 text-primary"></i>New Location</div>
    <div class="card-body">
        <form method="POST" action="{{ route('assets.locations.store') }}">
            @csrf
            <div class="mb-3"><label class="form-label required-star">Name</label><input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}">@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="mb-4"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3">{{ old('description') }}</textarea></div>
            <div class="d-flex gap-2"><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button><a href="{{ route('assets.locations.index') }}" class="btn btn-light">Cancel</a></div>
        </form>
    </div>
</div>
</div></div>
@endsection
