@extends('layouts.app')
@section('title','Add Contract Type')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hr.contract-types.index') }}">Contract Types</a></li>
    <li class="breadcrumb-item active">Add</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-7">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-file-earmark-text me-2 text-primary"></i>New Contract Type</div>
    <div class="card-body">
        <form method="POST" action="{{ route('hr.contract-types.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label required-star">Contract Name</label>
                    <input type="text" name="contract_name" class="form-control" value="{{ old('contract_name') }}" placeholder="e.g. Permanent Full-Time">
                </div>
                <div class="col-md-4">
                    <label class="form-label required-star">Code</label>
                    <input type="text" name="code" class="form-control" value="{{ old('code') }}" placeholder="e.g. PERM">
                </div>
                <div class="col-12">
                    <label class="form-label required-star">Employment Type</label>
                    <input type="text" name="employment_type" class="form-control" value="{{ old('employment_type') }}" placeholder="e.g. FULL TIME">
                </div>
                <div class="col-12">
                    <label class="form-label">Features</label>
                    <div class="row g-2">
                        @foreach(['has_annual_leave'=>'Annual Leave','has_payroll'=>'Payroll','has_overtime'=>'Overtime','has_allowance'=>'Allowance','has_emergency_fund'=>'Emergency Fund'] as $key => $label)
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="{{ $key }}" value="1" id="{{ $key }}" {{ old($key)?'checked':'' }}>
                                <label class="form-check-label" for="{{ $key }}">{{ $label }}</label>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="contract_description" class="form-control" rows="3">{{ old('contract_description') }}</textarea>
                </div>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button>
                <a href="{{ route('hr.contract-types.index') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div></div>
@endsection
