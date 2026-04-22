@extends('layouts.app')
@section('title','Record Stock Movement')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('inventory.movements.index') }}">Movements</a></li>
    <li class="breadcrumb-item active">Record</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-7">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-arrow-left-right me-2 text-warning"></i>Record Stock Movement</div>
    <div class="card-body">
        <form method="POST" action="{{ route('inventory.movements.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label required-star">Item</label>
                    <select name="inventory_item_id" id="itemSelect" class="form-select @error('inventory_item_id') is-invalid @enderror">
                        <option value="">— Select item —</option>
                        @foreach($items as $it)
                            <option value="{{ $it->id }}"
                                data-stock="{{ $it->quantity_on_hand }}"
                                data-unit="{{ $it->unit }}"
                                {{ old('inventory_item_id', $item?->id) == $it->id ? 'selected' : '' }}>
                                {{ $it->name }} ({{ number_format($it->quantity_on_hand, 2) }} {{ $it->unit }})
                            </option>
                        @endforeach
                    </select>
                    @error('inventory_item_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label required-star">Date</label>
                    <input type="date" name="date" class="form-control" value="{{ old('date', date('Y-m-d')) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label required-star">Movement Type</label>
                    <select name="type" id="moveType" class="form-select">
                        <option value="in" {{ old('type','in')=='in'?'selected':'' }}>Stock In</option>
                        <option value="out" {{ old('type')=='out'?'selected':'' }}>Stock Out</option>
                        <option value="adjustment" {{ old('type')=='adjustment'?'selected':'' }}>Adjustment</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label required-star" id="qtyLabel">Quantity</label>
                    <input type="number" name="quantity" class="form-control @error('quantity') is-invalid @enderror" step="0.01" min="0.01" value="{{ old('quantity') }}">
                    @error('quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Reference / Doc No.</label>
                    <input type="text" name="reference" class="form-control" value="{{ old('reference') }}" placeholder="PO / GRN number">
                </div>
                <div id="currentStockInfo" class="col-12 d-none">
                    <div class="alert alert-info py-2 mb-0 small">
                        <i class="bi bi-info-circle me-1"></i>
                        Current stock: <strong id="currentStockVal">0</strong> <span id="currentStockUnit"></span>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
                </div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-warning"><i class="bi bi-check-lg me-1"></i>Record</button>
                <a href="{{ route('inventory.movements.index') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div></div>
@endsection
@push('scripts')
<script>
const itemSelect = document.getElementById('itemSelect');
const moveType   = document.getElementById('moveType');
const qtyLabel   = document.getElementById('qtyLabel');
const infoBox    = document.getElementById('currentStockInfo');
const stockVal   = document.getElementById('currentStockVal');
const stockUnit  = document.getElementById('currentStockUnit');

function updateInfo() {
    const opt = itemSelect.options[itemSelect.selectedIndex];
    if (opt && opt.value) {
        stockVal.textContent  = parseFloat(opt.dataset.stock || 0).toFixed(2);
        stockUnit.textContent = opt.dataset.unit || '';
        infoBox.classList.remove('d-none');
    } else {
        infoBox.classList.add('d-none');
    }
    const type = moveType.value;
    qtyLabel.textContent = type === 'adjustment' ? 'New Quantity (set to)' : 'Quantity';
}
itemSelect.addEventListener('change', updateInfo);
moveType.addEventListener('change', updateInfo);
updateInfo();
</script>
@endpush
