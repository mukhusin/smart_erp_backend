@extends('layouts.app')
@section('title','Add Position')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hr.positions.index') }}">Positions</a></li>
    <li class="breadcrumb-item active">Add</li>
@endsection

@section('content')
<div class="row justify-content-center"><div class="col-lg-6">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-person-badge me-2 text-primary"></i>New Job Position</div>
    <div class="card-body">
        <form method="POST" action="{{ route('hr.positions.store') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label required-star">Position Title</label>
                <input type="text" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title') }}">
                @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Department</label>
                <select name="department_id" class="form-select">
                    <option value="">— Select Department —</option>
                    @foreach($departments as $d)
                        <option value="{{ $d->id }}" {{ old('department_id')==$d->id?'selected':'' }}>{{ $d->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-4">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="3">{{ old('description') }}</textarea>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button>
                <a href="{{ route('hr.positions.index') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div></div>
@endsection
