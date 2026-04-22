@extends('layouts.app')
@section('title','Record Payment')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('bills.payments.index') }}">Payments</a></li>
    <li class="breadcrumb-item active">Record Payment</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-cash-coin me-2 text-success"></i>Record Payment</div>
    <div class="card-body">
        <form method="POST" action="{{ route('bills.payments.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label required-star">Bill</label>
                    <select name="bill_id" class="form-select @error('bill_id') is-invalid @enderror" id="billSelect">
                        <option value="">— Select bill —</option>
                        @foreach($bills as $b)
                        <option value="{{ $b->id }}"
                            data-balance="{{ $b->balance }}"
                            {{ (old('bill_id',$bill?->id))==$b->id?'selected':'' }}>
                            {{ $b->bill_no ?? 'BILL-'.$b->id }} — {{ $b->supplier?->name }} (Balance: {{ number_format($b->balance,2) }})
                        </option>
                        @endforeach
                    </select>
                    @error('bill_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    @if($bill)<div class="form-text text-warning">Outstanding balance: <strong>{{ number_format($bill->balance,2) }}</strong></div>@endif
                </div>
                <div class="col-md-5">
                    <label class="form-label required-star">Payment Date</label>
                    <input type="date" name="payment_date" class="form-control" value="{{ old('payment_date', date('Y-m-d')) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label required-star">Amount</label>
                    <input type="number" name="amount" id="amount" class="form-control @error('amount') is-invalid @enderror"
                           step="0.01" min="0.01" value="{{ old('amount') }}">
                    @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label required-star">Method</label>
                    <select name="payment_method" class="form-select">
                        @foreach(['cash'=>'Cash','bank_transfer'=>'Bank Transfer','cheque'=>'Cheque','mobile_money'=>'Mobile Money','other'=>'Other'] as $v=>$l)
                        <option value="{{ $v }}" {{ old('payment_method','bank_transfer')==$v?'selected':'' }}>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Reference</label>
                    <input type="text" name="reference" class="form-control" value="{{ old('reference') }}" placeholder="Cheque no. / transfer ref">
                </div>
                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
                </div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Record Payment</button>
                <a href="{{ route('bills.payments.index') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
@push('scripts')
<script>
document.getElementById('billSelect').addEventListener('change', function(){
    const opt = this.options[this.selectedIndex];
    const balance = opt.dataset.balance;
    if (balance) document.getElementById('amount').value = parseFloat(balance).toFixed(2);
});
</script>
@endpush
