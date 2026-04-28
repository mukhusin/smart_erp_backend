@extends('layouts.app')
@section('title','New Payroll Sheet')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('payroll.sheets.index') }}">Payroll Sheets</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-header"><h6 class="mb-0">Create Payroll Sheet</h6></div>
    <div class="card-body">
        @if($errors->any())
            <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('payroll.sheets.store') }}">
            @csrf

            <div class="mb-3">
                <label class="form-label">Sheet Name <span class="text-danger">*</span></label>
                <input name="name" class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name','Salary File '.date('F Y')) }}" required>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Month <span class="text-danger">*</span></label>
                    <select name="month" class="form-select @error('month') is-invalid @enderror" required>
                        @foreach(['January','February','March','April','May','June','July','August','September','October','November','December'] as $m)
                            <option value="{{ $m }}" {{ old('month', date('F'))==$m?'selected':'' }}>{{ $m }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Year <span class="text-danger">*</span></label>
                    <input name="year" type="number" class="form-control @error('year') is-invalid @enderror"
                           value="{{ old('year', date('Y')) }}" min="2020" max="2099" required>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">From Date <span class="text-danger">*</span></label>
                    <input name="payroll_from_date" type="date" class="form-control @error('payroll_from_date') is-invalid @enderror"
                           value="{{ old('payroll_from_date') }}" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">To Date <span class="text-danger">*</span></label>
                    <input name="payroll_to_date" type="date" class="form-control @error('payroll_to_date') is-invalid @enderror"
                           value="{{ old('payroll_to_date') }}" required>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label">Number of Days <span class="text-danger">*</span></label>
                <input name="number_of_days" type="number" class="form-control @error('number_of_days') is-invalid @enderror"
                       value="{{ old('number_of_days', 30) }}" min="1" max="31" required>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i> Create Sheet
                </button>
                <a href="{{ route('payroll.sheets.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
