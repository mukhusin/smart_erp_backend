@extends('layouts.app')
@section('title','Edit Payroll Sheet')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('payroll.sheets.index') }}">Payroll Sheets</a></li>
    <li class="breadcrumb-item"><a href="{{ route('payroll.sheets.show', $sheet) }}">{{ $sheet->name }}</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-header"><h6 class="mb-0">Edit Payroll Sheet</h6></div>
    <div class="card-body">
        @if($errors->any())
            <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('payroll.sheets.update', $sheet) }}">
            @csrf @method('PUT')

            <div class="mb-3">
                <label class="form-label">Sheet Name</label>
                <input name="name" class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name', $sheet->name) }}" required>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">From Date</label>
                    <input name="payroll_from_date" type="date" class="form-control"
                           value="{{ old('payroll_from_date', $sheet->payroll_from_date) }}" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">To Date</label>
                    <input name="payroll_to_date" type="date" class="form-control"
                           value="{{ old('payroll_to_date', $sheet->payroll_to_date) }}" required>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label">Number of Days</label>
                <input name="number_of_days" type="number" class="form-control"
                       value="{{ old('number_of_days', $sheet->number_of_days) }}" min="1" max="31" required>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
                <a href="{{ route('payroll.sheets.show', $sheet) }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
