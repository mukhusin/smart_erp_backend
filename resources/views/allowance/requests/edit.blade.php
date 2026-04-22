@extends('layouts.app')
@section('title','Edit Allowance')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('allowance.requests.index') }}">Emp Allowances</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-7">
<div class="card shadow-sm">
    <div class="card-header fw-semibold">Edit Allowance</div>
    <div class="card-body">
        <form method="POST" action="{{ route('allowance.requests.update', $allowance) }}">
            @csrf @method('PUT')
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Items</label><input type="number" name="items" class="form-control" value="{{ $allowance->items }}" required min="1"></div>
                <div class="col-md-4"><label class="form-label">Amount/Item</label><input type="number" name="amount_per_item" class="form-control" step="0.01" value="{{ $allowance->amount_per_item }}" required></div>
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">@foreach(['pending','approved','rejected'] as $s)<option value="{{ $s }}" {{ $allowance->status==$s?'selected':'' }}>{{ ucfirst($s) }}</option>@endforeach</select>
                </div>
                <div class="col-md-4"><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-control" value="{{ $allowance->start_date }}" required></div>
                <div class="col-md-4"><label class="form-label">End Date</label><input type="date" name="end_date" class="form-control" value="{{ $allowance->end_date }}" required></div>
                <div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2">{{ $allowance->description }}</textarea></div>
            </div>
            <div class="d-flex gap-2 mt-3"><button class="btn btn-primary">Update</button><a href="{{ route('allowance.requests.index') }}" class="btn btn-secondary">Cancel</a></div>
        </form>
    </div>
</div>
</div></div>
@endsection
