@extends('layouts.app')
@section('title','New Purchase Request')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('procurement.requests.index') }}">Purchase Requests</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-xl-9">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold"><i class="bi bi-clipboard-plus me-2 text-primary"></i>New Purchase Request</div>
    <div class="card-body">
        <form method="POST" action="{{ route('procurement.requests.store') }}">
            @csrf
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label class="form-label required-star">Date</label>
                    <input type="date" name="date" class="form-control" value="{{ old('date', date('Y-m-d')) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">PR Number</label>
                    <input type="text" name="pr_number" class="form-control" value="{{ old('pr_number') }}" placeholder="PR-001">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Department</label>
                    <select name="department_id" class="form-select">
                        <option value="">— Select —</option>
                        @foreach($departments as $d)
                        <option value="{{ $d->id }}" {{ old('department_id')==$d->id?'selected':'' }}>{{ $d->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Requested By</label>
                    <select name="requested_by" class="form-select">
                        <option value="">— Select —</option>
                        @foreach($employees as $e)
                        <option value="{{ $e->id }}" {{ old('requested_by')==$e->id?'selected':'' }}>{{ $e->full_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
                </div>
            </div>

            <h6 class="fw-semibold mb-3">Requested Items</h6>
            <div class="table-responsive">
            <table class="table table-bordered" id="itemsTable">
                <thead class="table-light">
                    <tr><th style="width:35%">Item Name</th><th style="width:12%">Qty</th><th style="width:12%">Unit</th><th style="width:18%">Est. Cost</th><th>Notes</th><th style="width:40px"></th></tr>
                </thead>
                <tbody id="itemsBody">
                @php $oldItems = old('items', [['item_name'=>'','quantity'=>1,'unit'=>'','estimated_cost'=>'','notes'=>'']]) @endphp
                @foreach($oldItems as $i => $item)
                <tr>
                    <td><input type="text" name="items[{{ $i }}][item_name]" class="form-control form-control-sm" value="{{ $item['item_name'] ?? '' }}" placeholder="Item description" required></td>
                    <td><input type="number" name="items[{{ $i }}][quantity]" class="form-control form-control-sm" value="{{ $item['quantity'] ?? 1 }}" min="0.01" step="0.01"></td>
                    <td><input type="text" name="items[{{ $i }}][unit]" class="form-control form-control-sm" value="{{ $item['unit'] ?? '' }}" placeholder="pcs/kg/m"></td>
                    <td><input type="number" name="items[{{ $i }}][estimated_cost]" class="form-control form-control-sm" value="{{ $item['estimated_cost'] ?? '' }}" step="0.01" min="0" placeholder="0.00"></td>
                    <td><input type="text" name="items[{{ $i }}][notes]" class="form-control form-control-sm" value="{{ $item['notes'] ?? '' }}"></td>
                    <td><button type="button" class="btn btn-sm btn-outline-danger remove-item" {{ $loop->first ? 'disabled':'' }}><i class="bi bi-x"></i></button></td>
                </tr>
                @endforeach
                </tbody>
            </table>
            </div>
            <button type="button" id="addItem" class="btn btn-sm btn-outline-secondary mb-4"><i class="bi bi-plus me-1"></i>Add Item</button>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Submit Request</button>
                <a href="{{ route('procurement.requests.index') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
@push('scripts')
<script>
let idx = {{ count(old('items', [[]])) }};
document.getElementById('addItem').addEventListener('click', () => {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td><input type="text" name="items[${idx}][item_name]" class="form-control form-control-sm" placeholder="Item description" required></td><td><input type="number" name="items[${idx}][quantity]" class="form-control form-control-sm" value="1" min="0.01" step="0.01"></td><td><input type="text" name="items[${idx}][unit]" class="form-control form-control-sm" placeholder="pcs/kg/m"></td><td><input type="number" name="items[${idx}][estimated_cost]" class="form-control form-control-sm" step="0.01" min="0" placeholder="0.00"></td><td><input type="text" name="items[${idx}][notes]" class="form-control form-control-sm"></td><td><button type="button" class="btn btn-sm btn-outline-danger remove-item"><i class="bi bi-x"></i></button></td>`;
    document.getElementById('itemsBody').appendChild(tr);
    tr.querySelector('.remove-item').addEventListener('click', () => tr.remove());
    idx++;
});
document.querySelectorAll('.remove-item').forEach(b => b.addEventListener('click', function(){ this.closest('tr').remove(); }));
</script>
@endpush
