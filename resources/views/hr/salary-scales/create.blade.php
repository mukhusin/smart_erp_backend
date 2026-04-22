@extends('layouts.app')
@section('title','Add Salary Scale')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hr.salary-scales.index') }}">Salary Scales</a></li>
    <li class="breadcrumb-item active">Add</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-6">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-bar-chart-steps me-2 text-primary"></i>New Salary Scale</div>
    <div class="card-body">
        <form method="POST" action="{{ route('hr.salary-scales.store') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label required-star">Scale Name</label>
                <input type="text" name="name" class="form-control" value="{{ old('name') }}" placeholder="e.g. Grade A Step 1">
            </div>
            <div class="mb-3">
                <label class="form-label">Job Position</label>
                <select name="job_position_id" class="form-select">
                    <option value="">— Select Position —</option>
                    @foreach($positions as $p)
                        <option value="{{ $p->id }}" {{ old('job_position_id')==$p->id?'selected':'' }}>{{ $p->title }}</option>
                    @endforeach
                </select>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label required-star">Minimum (TZS)</label>
                    <input type="number" name="minimum" class="form-control" value="{{ old('minimum',0) }}" min="0" step="1000">
                </div>
                <div class="col-6">
                    <label class="form-label required-star">Maximum (TZS)</label>
                    <input type="number" name="maximum" class="form-control" value="{{ old('maximum',0) }}" min="0" step="1000">
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label">Description</label>
                <input type="text" name="description" class="form-control" value="{{ old('description') }}">
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button>
                <a href="{{ route('hr.salary-scales.index') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div></div>
@endsection
