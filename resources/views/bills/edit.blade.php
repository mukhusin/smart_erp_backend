@extends('layouts.app')
@section('title','Edit Bill')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('bills.index') }}">Bills</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-8">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-pencil me-2 text-primary"></i>Edit Bill — {{ $bill->bill_no ?? 'BILL-'.$bill->id }}</div>
    <div class="card-body">
        <form method="POST" action="{{ route('bills.update',$bill) }}">
            @csrf @method('PUT')
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label required-star">Supplier</label>
                    <select name="supplier_id" class="form-select">
                        @foreach($suppliers as $s)
                        <option value="{{ $s->id }}" {{ old('supplier_id',$bill->supplier_id)==$s->id?'selected':'' }}>{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Bill Number</label>
                    <input type="text" name="bill_no" class="form-control" value="{{ old('bill_no',$bill->bill_no) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label required-star">Issue Date</label>
                    <input type="date" name="issue_date" class="form-control" value="{{ old('issue_date',$bill->issue_date?->format('Y-m-d')) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Due Date</label>
                    <input type="date" name="due_date" class="form-control" value="{{ old('due_date',$bill->due_date?->format('Y-m-d')) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label required-star">Subtotal</label>
                    <input type="number" name="subtotal" id="subtotal" class="form-control" step="0.01" min="0" value="{{ old('subtotal',$bill->subtotal) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Tax Amount</label>
                    <input type="number" name="tax_amount" id="taxAmount" class="form-control" step="0.01" min="0" value="{{ old('tax_amount',$bill->tax_amount) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Total</label>
                    <input type="text" id="totalDisplay" class="form-control bg-light" readonly>
                </div>
                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3">{{ old('notes',$bill->notes) }}</textarea>
                </div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update</button>
                <a href="{{ route('bills.show',$bill) }}" class="btn btn-light">Cancel</a>
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
