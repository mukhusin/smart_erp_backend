@extends('layouts.app')
@section('title','New Purchase Order')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('procurement.orders.index') }}">Purchase Orders</a></li>
    <li class="breadcrumb-item active">New PO</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-xl-10">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-cart-check me-2 text-primary"></i>New Purchase Order</div>
    <div class="card-body">
        <form method="POST" action="{{ route('procurement.orders.store') }}">
            @csrf
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label class="form-label required-star">Date</label>
                    <input type="date" name="date" class="form-control" value="{{ old('date', date('Y-m-d')) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">PO Number</label>
                    <input type="text" name="po_number" class="form-control" value="{{ old('po_number') }}" placeholder="PO-001">
                </div>
                <div class="col-md-6">
                    <label class="form-label required-star">Supplier</label>
                    <select name="supplier_id" class="form-select @error('supplier_id') is-invalid @enderror">
                        <option value="">— Select supplier —</option>
                        @foreach($suppliers as $s)
                        <option value="{{ $s->id }}" {{ old('supplier_id')==$s->id?'selected':'' }}>{{ $s->name }}</option>
                        @endforeach
                    </select>
                    @error('supplier_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Link to Purchase Request</label>
                    <select name="purchase_request_id" class="form-select">
                        <option value="">— None —</option>
                        @foreach($prs as $pr)
                        <option value="{{ $pr->id }}" {{ old('purchase_request_id',$selectedPr?->id)==$pr->id?'selected':'' }}>{{ $pr->pr_number ?? 'PR-'.$pr->id }} — {{ $pr->date?->format('d M Y') }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
                </div>
            </div>

            <h6 class="fw-semibold mb-3">Order Items</h6>
            <div class="table-responsive">
            <table class="table table-bordered">
                <thead class="table-light">
                    <tr><th style="width:30%">Item</th><th style="width:10%">Qty</th><th style="width:10%">Unit</th><th style="width:15%">Unit Price</th><th style="width:15%">Total</th><th>Notes</th><th style="width:40px"></th></tr>
                </thead>
                <tbody id="itemsBody">
                @php $oldItems = old('items', [['item_name'=>'','quantity'=>1,'unit'=>'','unit_price'=>'','notes'=>'']]) @endphp
                @if($selectedPr && !old('items'))
                    @php $oldItems = $selectedPr->items->map(fn($i) => ['item_name'=>$i->item_name,'quantity'=>$i->quantity,'unit'=>$i->unit,'unit_price'=>'','notes'=>$i->notes])->toArray() @endphp
                @endif
                @foreach($oldItems as $i => $item)
                <tr>
                    <td><input type="text" name="items[{{ $i }}][item_name]" class="form-control form-control-sm" value="{{ $item['item_name'] ?? '' }}" required></td>
                    <td><input type="number" name="items[{{ $i }}][quantity]" class="form-control form-control-sm qty" value="{{ $item['quantity'] ?? 1 }}" min="0.01" step="0.01"></td>
                    <td><input type="text" name="items[{{ $i }}][unit]" class="form-control form-control-sm" value="{{ $item['unit'] ?? '' }}"></td>
                    <td><input type="number" name="items[{{ $i }}][unit_price]" class="form-control form-control-sm price" value="{{ $item['unit_price'] ?? '' }}" step="0.01" min="0"></td>
                    <td><input type="text" class="form-control form-control-sm line-total bg-light" readonly></td>
                    <td><input type="text" name="items[{{ $i }}][notes]" class="form-control form-control-sm" value="{{ $item['notes'] ?? '' }}"></td>
                    <td><button type="button" class="btn btn-sm btn-outline-danger remove-item" {{ $loop->first ? 'disabled':'' }}><i class="bi bi-x"></i></button></td>
                </tr>
                @endforeach
                </tbody>
                <tfoot>
                    <tr class="table-light"><td colspan="4" class="text-end fw-semibold">Grand Total</td><td id="grandTotal" class="fw-bold">0.00</td><td colspan="2"></td></tr>
                </tfoot>
            </table>
            </div>
            <button type="button" id="addItem" class="btn btn-sm btn-outline-secondary mb-4"><i class="bi bi-plus me-1"></i>Add Item</button>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save PO</button>
                <a href="{{ route('procurement.orders.index') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
@push('scripts')
<script>
let idx = {{ count(old('items', $selectedPr?->items??[[]])) }};
function calcRow(row) {
    const qty = parseFloat(row.querySelector('.qty').value)||0;
    const price = parseFloat(row.querySelector('.price').value)||0;
    row.querySelector('.line-total').value = (qty*price).toFixed(2);
    calcGrand();
}
function calcGrand() {
    let total=0;
    document.querySelectorAll('.line-total').forEach(f=>total+=parseFloat(f.value)||0);
    document.getElementById('grandTotal').textContent=total.toFixed(2);
}
function bindRow(row) {
    row.querySelector('.qty').addEventListener('input',()=>calcRow(row));
    row.querySelector('.price').addEventListener('input',()=>calcRow(row));
    row.querySelector('.remove-item').addEventListener('click',()=>{row.remove();calcGrand();});
    calcRow(row);
}
document.querySelectorAll('#itemsBody tr').forEach(bindRow);
document.getElementById('addItem').addEventListener('click',()=>{
    const tr=document.createElement('tr');
    tr.innerHTML=`<td><input type="text" name="items[${idx}][item_name]" class="form-control form-control-sm" required></td><td><input type="number" name="items[${idx}][quantity]" class="form-control form-control-sm qty" value="1" min="0.01" step="0.01"></td><td><input type="text" name="items[${idx}][unit]" class="form-control form-control-sm"></td><td><input type="number" name="items[${idx}][unit_price]" class="form-control form-control-sm price" step="0.01" min="0"></td><td><input type="text" class="form-control form-control-sm line-total bg-light" readonly></td><td><input type="text" name="items[${idx}][notes]" class="form-control form-control-sm"></td><td><button type="button" class="btn btn-sm btn-outline-danger remove-item"><i class="bi bi-x"></i></button></td>`;
    document.getElementById('itemsBody').appendChild(tr);
    bindRow(tr);idx++;
});
</script>
@endpush
