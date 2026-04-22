@extends('layouts.app')
@section('title','New Account')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('accounting.chart.index') }}">Chart of Accounts</a></li>
    <li class="breadcrumb-item active">New Account</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold">
        <i class="bi bi-list-nested me-2 text-primary"></i>New Account
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('accounting.chart.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Account Code</label>
                    <input type="text" name="code" class="form-control" value="{{ old('code') }}" placeholder="e.g. 1001">
                </div>
                <div class="col-md-8">
                    <label class="form-label required-star">Account Name</label>
                    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                           value="{{ old('name') }}" placeholder="e.g. Cash and Bank">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label required-star">Account Type</label>
                    <select name="type" class="form-select @error('type') is-invalid @enderror">
                        <option value="">— Select type —</option>
                        @foreach(['asset'=>'Asset','liability'=>'Liability','equity'=>'Equity','revenue'=>'Revenue','expense'=>'Expense'] as $v => $l)
                            <option value="{{ $v }}" {{ old('type')==$v?'selected':'' }}>{{ $l }}</option>
                        @endforeach
                    </select>
                    @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Parent Account</label>
                    <select name="parent_id" class="form-select">
                        <option value="">— None (top-level) —</option>
                        @foreach($parents as $p)
                            <option value="{{ $p->id }}" {{ old('parent_id')==$p->id?'selected':'' }}>{{ $p->code ? $p->code.' - ':'' }}{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3">{{ old('description') }}</textarea>
                </div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button>
                <a href="{{ route('accounting.chart.index') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
