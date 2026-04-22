@extends('layouts.app')
@section('title','Edit Contract Type')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hr.contract-types.index') }}">Contract Types</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-7">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-pencil me-2 text-primary"></i>Edit: {{ $contractType->contract_name }}</div>
    <div class="card-body">
        <form method="POST" action="{{ route('hr.contract-types.update',$contractType) }}">
            @csrf @method('PUT')
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label required-star">Contract Name</label>
                    <input type="text" name="contract_name" class="form-control" value="{{ old('contract_name',$contractType->contract_name) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label required-star">Code</label>
                    <input type="text" name="code" class="form-control" value="{{ old('code',$contractType->code) }}">
                </div>
                <div class="col-12">
                    <label class="form-label required-star">Employment Type</label>
                    <input type="text" name="employment_type" class="form-control" value="{{ old('employment_type',$contractType->employment_type) }}">
                </div>
                <div class="col-12">
                    <label class="form-label">Features</label>
                    <div class="row g-2">
                        @foreach(['has_annual_leave'=>'Annual Leave','has_payroll'=>'Payroll','has_overtime'=>'Overtime','has_allowance'=>'Allowance','has_emergency_fund'=>'Emergency Fund'] as $key => $label)
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="{{ $key }}" value="1" id="{{ $key }}_e"
                                       {{ old($key,$contractType->{$key})?'checked':'' }}>
                                <label class="form-check-label" for="{{ $key }}_e">{{ $label }}</label>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active_e" {{ old('is_active',$contractType->is_active)?'checked':'' }}>
                        <label class="form-check-label" for="is_active_e">Active</label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="contract_description" class="form-control" rows="3">{{ old('contract_description',$contractType->contract_description) }}</textarea>
                </div>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">Update</button>
                <a href="{{ route('hr.contract-types.index') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div></div>
@endsection
