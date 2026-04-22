@extends('layouts.app')
@section('title','Edit Rating Scale')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('appraisals.scales.index') }}">Rating Scales</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-6">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-pencil me-2 text-primary"></i>Edit Scale — {{ $scale->scale }}</div>
    <div class="card-body">
        <form method="POST" action="{{ route('appraisals.scales.update',$scale) }}">
            @csrf @method('PUT')
            <div class="mb-3"><label class="form-label required-star">Scale Label</label><input type="text" name="scale" class="form-control" value="{{ old('scale',$scale->scale) }}"></div>
            <div class="row g-3 mb-3">
                <div class="col-6"><label class="form-label required-star">Min %</label><input type="number" name="minimum_percent" class="form-control" value="{{ old('minimum_percent',$scale->minimum_percent) }}" min="0" max="100" step="0.01"></div>
                <div class="col-6"><label class="form-label required-star">Max %</label><input type="number" name="maximum_percent" class="form-control" value="{{ old('maximum_percent',$scale->maximum_percent) }}" min="0" max="100" step="0.01"></div>
            </div>
            <div class="mb-4"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3">{{ old('description',$scale->description) }}</textarea></div>
            <div class="d-flex gap-2"><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update</button><a href="{{ route('appraisals.scales.index') }}" class="btn btn-light">Cancel</a></div>
        </form>
    </div>
</div>
</div></div>
@endsection
