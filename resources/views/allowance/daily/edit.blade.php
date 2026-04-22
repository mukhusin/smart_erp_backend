@extends('layouts.app')
@section('title','Edit Daily Allowance')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('allowance.daily.index') }}">Daily Allowances</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-8">
<div class="card shadow-sm">
    <div class="card-header fw-semibold">Edit Daily Allowance — {{ $allowance->employee?->full_name }}</div>
    <div class="card-body">
        <form method="POST" action="{{ route('allowance.daily.update', $allowance) }}">
            @csrf @method('PUT')
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Category</label>
                    <select name="allowance_category_id" class="form-select" required>
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}" {{ $allowance->allowance_category_id == $cat->id ? 'selected' : '' }}>
                                {{ $cat->category }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Items</label>
                    <input type="number" name="number_of_items" id="items" class="form-control" min="1"
                           value="{{ $allowance->number_of_items }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Amount/Item</label>
                    <input type="number" name="amount_per_item" id="amtper" class="form-control" step="0.01" min="0"
                           value="{{ $allowance->amount_per_item }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Total Amount</label>
                    <input type="number" name="amount" id="amttotal" class="form-control" step="0.01"
                           value="{{ $allowance->amount }}" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date</label>
                    <input type="date" name="date" class="form-control" value="{{ $allowance->date }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        @foreach(['pending','approved','rejected'] as $s)
                            <option value="{{ $s }}" {{ $allowance->status == $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Remark</label>
                    <textarea name="remark" class="form-control" rows="2">{{ $allowance->remark }}</textarea>
                </div>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">Update</button>
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
