@extends('layouts.app')
@section('title','Add Department')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hr.departments.index') }}">Departments</a></li>
    <li class="breadcrumb-item active">Add</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold">
        <i class="bi bi-building me-2 text-primary"></i>New Department
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('hr.departments.store') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label required-star">Department Name</label>
                <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name') }}" placeholder="e.g. Finance">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Short Name</label>
                <input type="text" name="short_name" class="form-control" value="{{ old('short_name') }}" placeholder="e.g. FIN">
            </div>
            <div class="mb-3">
                <label class="form-label">Parent Department</label>
                <select name="parent_id" class="form-select">
                    <option value="">— None (Top-level department) —</option>
                    @foreach($parents as $p)
                        <option value="{{ $p->id }}" {{ old('parent_id')==$p->id?'selected':'' }}>{{ $p->name }}</option>
                    @endforeach
                </select>
                <div class="form-text">Select a parent to create a section under an existing department.</div>
            </div>
            <div class="mb-3">
                <label class="form-label">Branch</label>
                <select name="company_branch_id" class="form-select">
                    <option value="">— All Branches —</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ old('company_branch_id')==$b->id?'selected':'' }}>{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-4">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="3">{{ old('description') }}</textarea>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button>
                <a href="{{ route('hr.departments.index') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
