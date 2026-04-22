@extends('layouts.app')
@section('title','Edit Department')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hr.departments.index') }}">Departments</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold">
        <i class="bi bi-pencil me-2 text-primary"></i>Edit Department — {{ $department->name }}
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('hr.departments.update', $department) }}">
            @csrf @method('PUT')
            <div class="mb-3">
                <label class="form-label required-star">Department Name</label>
                <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name', $department->name) }}">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Short Name</label>
                <input type="text" name="short_name" class="form-control" value="{{ old('short_name', $department->short_name) }}">
            </div>
            <div class="mb-3">
                <label class="form-label">Parent Department</label>
                <select name="parent_id" class="form-select">
                    <option value="">— None —</option>
                    @foreach($parents as $p)
                        <option value="{{ $p->id }}" {{ old('parent_id', $department->parent_id)==$p->id?'selected':'' }}>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Branch</label>
                <select name="company_branch_id" class="form-select">
                    <option value="">— All Branches —</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ old('company_branch_id', $department->company_branch_id)==$b->id?'selected':'' }}>{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-4">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="3">{{ old('description', $department->description) }}</textarea>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update</button>
                <a href="{{ route('hr.departments.index') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
