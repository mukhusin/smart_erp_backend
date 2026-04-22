@extends('layouts.app')
@section('title','New Emp Allowance')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('allowance.requests.index') }}">Emp Allowances</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-8">
<div class="card shadow-sm">
    <div class="card-header fw-semibold">New Employee Allowance</div>
    <div class="card-body">
        <form method="POST" action="{{ route('allowance.requests.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Employee *</label><select name="employee_id" class="form-select" required><option value="">— Select —</option>@foreach($employees as $e)<option value="{{ $e->id }}" {{ old('employee_id')==$e->id?'selected':'' }}>{{ $e->full_name }}</option>@endforeach</select></div>
                <div class="col-md-6"><label class="form-label">Category *</label><select name="allowance_category_id" class="form-select" required><option value="">— Select —</option>@foreach($categories as $c)<option value="{{ $c->id }}" {{ old('allowance_category_id')==$c->id?'selected':'' }}>{{ $c->category }} ({{ $c->duration }})</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label">Items *</label><input type="number" name="items" class="form-control" min="1" value="{{ old('items',1) }}" required></div>
                <div class="col-md-3"><label class="form-label">Amount / Item *</label><input type="number" name="amount_per_item" class="form-control" step="0.01" min="0" value="{{ old('amount_per_item') }}" required></div>
                <div class="col-md-3"><label class="form-label">Start Date *</label><input type="date" name="start_date" class="form-control" value="{{ old('start_date') }}" required></div>
                <div class="col-md-3"><label class="form-label">End Date *</label><input type="date" name="end_date" class="form-control" value="{{ old('end_date') }}" required></div>
                <div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2">{{ old('description') }}</textarea></div>
            </div>
            <div class="d-flex gap-2 mt-3"><button class="btn btn-primary">Submit</button><a href="{{ route('allowance.requests.index') }}" class="btn btn-secondary">Cancel</a></div>
        </form>
    </div>
</div>
</div></div>
@endsection
