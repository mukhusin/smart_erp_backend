@extends('layouts.app')
@section('title','New Appraisal Template')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('appraisals.templates.index') }}">Templates</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-7">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-clipboard-check me-2 text-primary"></i>New Appraisal Template</div>
    <div class="card-body">
        <form method="POST" action="{{ route('appraisals.templates.store') }}">
            @csrf
            <div class="mb-3"><label class="form-label required-star">Appraisal Title</label><input type="text" name="appraisal_title" class="form-control @error('appraisal_title') is-invalid @enderror" value="{{ old('appraisal_title') }}" placeholder="e.g. Annual Performance Review FY2026">@error('appraisal_title')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="row g-3 mb-3">
                <div class="col-md-6"><label class="form-label">Department</label><select name="department_id" class="form-select"><option value="">— Any —</option>@foreach($departments as $d)<option value="{{ $d->id }}" {{ old('department_id')==$d->id?'selected':'' }}>{{ $d->name }}</option>@endforeach</select></div>
                <div class="col-md-6"><label class="form-label">Job Position</label><select name="job_position_id" class="form-select"><option value="">— Any —</option>@foreach($positions as $p)<option value="{{ $p->id }}" {{ old('job_position_id')==$p->id?'selected':'' }}>{{ $p->title }}</option>@endforeach</select></div>
            </div>
            <div class="mb-4"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3">{{ old('description') }}</textarea></div>
            <div class="d-flex gap-2"><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button><a href="{{ route('appraisals.templates.index') }}" class="btn btn-light">Cancel</a></div>
        </form>
    </div>
</div>
</div></div>
@endsection
