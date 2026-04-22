@extends('layouts.app')
@section('title','New Daily Allowance')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('allowance.daily.index') }}">Daily Allowances</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-8">
<div class="card shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-cash-coin me-2"></i>New Daily Allowance</div>
    <div class="card-body">
        <form method="POST" action="{{ route('allowance.daily.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Employee <span class="text-danger">*</span></label>
                    <select name="employee_id" class="form-select @error('employee_id') is-invalid @enderror" required>
                        <option value="">— Select —</option>
                        @foreach($employees as $emp)
                            <option value="{{ $emp->id }}" {{ old('employee_id') == $emp->id ? 'selected' : '' }}>
                                {{ $emp->full_name }}
                            </option>
                        @endforeach
                    </select>
                    @error('employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Allowance Category <span class="text-danger">*</span></label>
                    <select name="allowance_category_id" class="form-select @error('allowance_category_id') is-invalid @enderror" required>
                        <option value="">— Select —</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}" {{ old('allowance_category_id') == $cat->id ? 'selected' : '' }}>
                                {{ $cat->category }}
                            </option>
                        @endforeach
                    </select>
                    @error('allowance_category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Dept. Allowance (optional)</label>
                    <select name="department_allowance_id" class="form-select">
                        <option value="">— None —</option>
                        @foreach($deptAllowances as $da)
                            <option value="{{ $da->id }}" {{ old('department_allowance_id') == $da->id ? 'selected' : '' }}>
                                {{ $da->category?->category }} – {{ $da->department?->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Items / Days <span class="text-danger">*</span></label>
                    <input type="number" name="number_of_items" id="items" class="form-control" min="1"
                           value="{{ old('number_of_items', 1) }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Amount per Item <span class="text-danger">*</span></label>
                    <input type="number" name="amount_per_item" id="amtper" class="form-control" step="0.01" min="0"
                           value="{{ old('amount_per_item', 0) }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Total Amount</label>
                    <input type="number" name="amount" id="amttotal" class="form-control" step="0.01" min="0"
                           value="{{ old('amount', 0) }}" readonly>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Date</label>
                    <input type="date" name="date" class="form-control" value="{{ old('date', now()->toDateString()) }}">
                </div>
                <div class="col-12">
                    <label class="form-label">Remark</label>
                    <textarea name="remark" class="form-control" rows="2">{{ old('remark') }}</textarea>
                </div>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">Submit</button>
                <a href="{{ route('allowance.daily.index') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('#items,#amtper').forEach(el => el.addEventListener('input', () => {
    const items = parseFloat(document.getElementById('items').value) || 0;
    const per   = parseFloat(document.getElementById('amtper').value) || 0;
    document.getElementById('amttotal').value = (items * per).toFixed(2);
}));
</script>
@endpush
