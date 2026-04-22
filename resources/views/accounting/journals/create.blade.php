@extends('layouts.app')
@section('title','New Journal Entry')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('accounting.journals.index') }}">Journal Entries</a></li>
    <li class="breadcrumb-item active">New Entry</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-xl-10">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold">
        <i class="bi bi-journals me-2 text-primary"></i>New Journal Entry
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('accounting.journals.store') }}" id="journalForm">
            @csrf
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label class="form-label required-star">Date</label>
                    <input type="date" name="date" class="form-control @error('date') is-invalid @enderror"
                           value="{{ old('date', date('Y-m-d')) }}">
                    @error('date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">Reference No.</label>
                    <input type="text" name="reference_no" class="form-control" value="{{ old('reference_no') }}" placeholder="JE-001">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control" value="{{ old('description') }}" placeholder="Brief description">
                </div>
            </div>

            <h6 class="mb-3 fw-semibold">Journal Lines</h6>
            <div class="table-responsive">
            <table class="table table-bordered" id="linesTable">
                <thead class="table-light">
                    <tr>
                        <th style="width:35%">Account</th>
                        <th>Description</th>
                        <th style="width:15%" class="text-end">Debit</th>
                        <th style="width:15%" class="text-end">Credit</th>
                        <th style="width:40px"></th>
                    </tr>
                </thead>
                <tbody id="linesBody">
                    @php $oldLines = old('lines', [['account_id'=>'','description'=>'','debit'=>'','credit'=>''],['account_id'=>'','description'=>'','debit'=>'','credit'=>'']]) @endphp
                    @foreach($oldLines as $i => $line)
                    <tr>
                        <td>
                            <select name="lines[{{ $i }}][account_id]" class="form-select form-select-sm">
                                <option value="">— Select account —</option>
                                @foreach($accounts as $acc)
                                <option value="{{ $acc->id }}" {{ ($line['account_id'] ?? '')==$acc->id?'selected':'' }}>
                                    {{ $acc->code ? $acc->code.' - ':'' }}{{ $acc->name }}
                                </option>
                                @endforeach
                            </select>
                        </td>
                        <td><input type="text" name="lines[{{ $i }}][description]" class="form-control form-control-sm" value="{{ $line['description'] ?? '' }}"></td>
                        <td><input type="number" name="lines[{{ $i }}][debit]" class="form-control form-control-sm text-end debit-field" step="0.01" min="0" value="{{ $line['debit'] ?? '' }}"></td>
                        <td><input type="number" name="lines[{{ $i }}][credit]" class="form-control form-control-sm text-end credit-field" step="0.01" min="0" value="{{ $line['credit'] ?? '' }}"></td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger remove-line" {{ $i < 2 ? 'disabled':'' }}><i class="bi bi-x"></i></button></td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="table-light fw-semibold">
                        <td colspan="2" class="text-end">Totals</td>
                        <td class="text-end" id="totalDebit">0.00</td>
                        <td class="text-end" id="totalCredit">0.00</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            </div>
            <button type="button" id="addLine" class="btn btn-sm btn-outline-secondary mb-3">
                <i class="bi bi-plus me-1"></i>Add Line
            </button>

            <div id="balanceAlert" class="alert alert-danger d-none"><i class="bi bi-exclamation-triangle me-2"></i>Entry does not balance. Debits must equal credits.</div>
            <div id="balanceOk"    class="alert alert-success d-none"><i class="bi bi-check-circle me-2"></i>Entry is balanced.</div>

            <div class="d-flex gap-2 mt-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Draft</button>
                <a href="{{ route('accounting.journals.index') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection

@push('scripts')
<script>
const accountsJson = @json($accounts->map(fn($a) => ['id'=>$a->id,'label'=>($a->code?$a->code.' - ':'').$a->name]));

let lineIdx = {{ count(old('lines', [[],[]])) }};

function updateTotals() {
    let d = 0, c = 0;
    document.querySelectorAll('.debit-field').forEach(f  => d += parseFloat(f.value)||0);
    document.querySelectorAll('.credit-field').forEach(f => c += parseFloat(f.value)||0);
    document.getElementById('totalDebit').textContent  = d.toFixed(2);
    document.getElementById('totalCredit').textContent = c.toFixed(2);
    const balanced = Math.abs(d - c) < 0.01;
    document.getElementById('balanceAlert').classList.toggle('d-none', balanced || (d === 0 && c === 0));
    document.getElementById('balanceOk').classList.toggle('d-none', !balanced || (d === 0 && c === 0));
}

function buildSelect(name, selectedId) {
    let opts = '<option value="">— Select account —</option>';
    accountsJson.forEach(a => opts += `<option value="${a.id}" ${a.id==selectedId?'selected':''}>${a.label}</option>`);
    return `<select name="${name}" class="form-select form-select-sm">${opts}</select>`;
}

document.getElementById('addLine').addEventListener('click', () => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td>${buildSelect('lines['+lineIdx+'][account_id]', '')}</td>
        <td><input type="text" name="lines[${lineIdx}][description]" class="form-control form-control-sm"></td>
        <td><input type="number" name="lines[${lineIdx}][debit]"  class="form-control form-control-sm text-end debit-field"  step="0.01" min="0"></td>
        <td><input type="number" name="lines[${lineIdx}][credit]" class="form-control form-control-sm text-end credit-field" step="0.01" min="0"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger remove-line"><i class="bi bi-x"></i></button></td>`;
    document.getElementById('linesBody').appendChild(tr);
    tr.querySelectorAll('.debit-field,.credit-field').forEach(f => f.addEventListener('input', updateTotals));
    tr.querySelector('.remove-line').addEventListener('click', () => { tr.remove(); updateTotals(); });
    lineIdx++;
});

document.querySelectorAll('.debit-field,.credit-field').forEach(f => f.addEventListener('input', updateTotals));
document.querySelectorAll('.remove-line').forEach(b => b.addEventListener('click', function(){ this.closest('tr').remove(); updateTotals(); }));
updateTotals();
</script>
@endpush
