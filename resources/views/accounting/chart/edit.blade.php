@extends('layouts.app')
@section('title','Edit Account')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('accounting.chart.index') }}">Chart of Accounts</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold">
        <i class="bi bi-pencil me-2 text-primary"></i>Edit Account — {{ $chart->name }}
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('accounting.chart.update',$chart) }}">
            @csrf @method('PUT')
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Code</label>
                    <input type="text" name="code" class="form-control" value="{{ old('code',$chart->code) }}">
                </div>
                <div class="col-md-8">
                    <label class="form-label required-star">Name</label>
                    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                           value="{{ old('name',$chart->name) }}">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label required-star">Type</label>
                    <select name="type" class="form-select">
                        @foreach(['asset'=>'Asset','liability'=>'Liability','equity'=>'Equity','revenue'=>'Revenue','expense'=>'Expense'] as $v => $l)
                            <option value="{{ $v }}" {{ old('type',$chart->type)==$v?'selected':'' }}>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Parent Account</label>
                    <select name="parent_id" class="form-select">
                        <option value="">— None —</option>
                        @foreach($parents as $p)
                            <option value="{{ $p->id }}" {{ old('parent_id',$chart->parent_id)==$p->id?'selected':'' }}>{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3">{{ old('description',$chart->description) }}</textarea>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive"
                               {{ old('is_active',$chart->is_active)?'checked':'' }}>
                        <label class="form-check-label" for="isActive">Active</label>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update</button>
                <a href="{{ route('accounting.chart.index') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
