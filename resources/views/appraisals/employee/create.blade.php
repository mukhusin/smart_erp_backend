@extends('layouts.app')
@section('title','New Employee Appraisal')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('appraisals.employee.index') }}">Employee Appraisals</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection
@section('content')
<div class="mb-3">
    <h5 class="page-title">New Employee Appraisal</h5>
    <p class="page-subtitle">Assign an appraisal template to an employee for a given period</p>
</div>

<div class="row justify-content-center">
<div class="col-lg-6">
<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ route('appraisals.employee.store') }}">
            @csrf

            <div class="mb-3">
                <label class="form-label fw-semibold">Employee <span class="text-danger">*</span></label>
                <select name="employee_id" class="form-select @error('employee_id') is-invalid @enderror" required>
                    <option value="">— Select Employee —</option>
                    @foreach($employees as $emp)
                        <option value="{{ $emp->id }}" @selected(old('employee_id') == $emp->id)>{{ $emp->full_name }}</option>
                    @endforeach
                </select>
                @error('employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Appraisal Template <span class="text-danger">*</span></label>
                <select name="department_appraisal_id" class="form-select @error('department_appraisal_id') is-invalid @enderror" required>
                    <option value="">— Select Template —</option>
                    @foreach($templates as $t)
                        <option value="{{ $t->id }}" @selected(old('department_appraisal_id') == $t->id)>{{ $t->appraisal_title }}</option>
                    @endforeach
                </select>
                @error('department_appraisal_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold">Period <span class="text-danger">*</span></label>
                <input type="text" name="period" class="form-control @error('period') is-invalid @enderror"
                       placeholder="e.g. Q1 2026 or 2026" value="{{ old('period') }}" required maxlength="50">
                @error('period')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Create Appraisal</button>
                <a href="{{ route('appraisals.employee.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
