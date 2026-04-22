@extends('layouts.app')
@section('title','New Bill')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('bills.index') }}">Bills</a></li>
    <li class="breadcrumb-item active">New Bill</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-8">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-file-text me-2 text-primary"></i>New Bill</div>
    <div class="card-body">
        <form method="POST" action="{{ route('bills.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label required-star">Supplier</label>
                    <select name="supplier_id" class="form-select @error('supplier_id') is-invalid @enderror">
                        <option value="">— Select supplier —</option>
                        @foreach($suppliers as $s)
                        <option value="{{ $s->id }}" {{ old('supplier_id')==$s->id?'selected':'' }}>{{ $s->name }}</option>
                        @endforeach
                    </select>
                    @error('supplier_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Bill Number</label>
                    <input type="text" name="bill_no" class="form-control" value="{{ old('bill_no') }}" placeholder="INV-001">
                </div>
                <div class="col-md-4">
                    <label class="form-label required-star">Issue Date</label>
                    <input type="date" name="issue_date" class="form-control @error('issue_date') is-invalid @enderror"
                           value="{{ old('issue_date', date('Y-m-d')) }}">
                    @error('issue_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Due Date</label>
                    <input type="date" name="due_date" class="form-control" value="{{ old('due_date') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label required-star">Subtotal</label>
                    <input type="number" name="subtotal" id="subtotal" class="form-control @error('subtotal') is-invalid @enderror"
                           step="0.01" min="0" value="{{ old('subtotal',0) }}">
                    @error('subtotal')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Tax Amount</label>
                    <input type="number" name="tax_amount" id="taxAmount" class="form-control" step="0.01" min="0" value="{{ old('tax_amount',0) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Total Amount</label>
                    <input type="text" id="totalDisplay" class="form-control bg-light" readonly value="0.00">
                </div>
                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3">{{ old('notes') }}</textarea>
                </div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button>
                <a href="{{ route('bills.index') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection

@push('scripts')
<script>
function calcTotal() {
    const sub = parseFloat(document.getElementById('subtotal').value)||0;
    const tax = parseFloat(document.getElementById('taxAmount').value)||0;
    document.getElementById('totalDisplay').value = (sub+tax).toFixed(2);
}
document.getElementById('subtotal').addEventListener('input', calcTotal);
document.getElementById('taxAmount').addEventListener('input', calcTotal);
calcTotal();
</script>
@endpush
