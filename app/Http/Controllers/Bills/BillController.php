<?php
namespace App\Http\Controllers\Bills;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Supplier;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class BillController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = Bill::where('company_id', $this->ctx->id())->with('supplier');
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        $bills     = $query->latest('issue_date')->paginate(20);
        $suppliers = Supplier::where('company_id', $this->ctx->id())->where('is_active', true)->orderBy('name')->get();
        return view('bills.index', compact('bills', 'suppliers'));
    }

    public function create()
    {
        $suppliers = Supplier::where('company_id', $this->ctx->id())->where('is_active', true)->orderBy('name')->get();
        return view('bills.create', compact('suppliers'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id'  => 'required|exists:suppliers,id',
            'bill_no'      => 'nullable|string|max:60',
            'issue_date'   => 'required|date',
            'due_date'     => 'nullable|date|after_or_equal:issue_date',
            'subtotal'     => 'required|numeric|min:0',
            'tax_amount'   => 'nullable|numeric|min:0',
            'notes'        => 'nullable|string',
        ]);
        $data['company_id']   = $this->ctx->id();
        $data['tax_amount']   = $data['tax_amount'] ?? 0;
        $data['total_amount'] = $data['subtotal'] + $data['tax_amount'];
        $data['paid_amount']  = 0;
        $data['status']       = 'open';
        Bill::create($data);
        return redirect()->route('bills.index')->with('success', 'Bill created.');
    }

    public function show(Bill $bill)
    {
        $bill->load('supplier', 'payments');
        return view('bills.show', compact('bill'));
    }

    public function edit(Bill $bill)
    {
        $suppliers = Supplier::where('company_id', $this->ctx->id())->where('is_active', true)->orderBy('name')->get();
        return view('bills.edit', compact('bill', 'suppliers'));
    }

    public function update(Request $request, Bill $bill)
    {
        $data = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'bill_no'     => 'nullable|string|max:60',
            'issue_date'  => 'required|date',
            'due_date'    => 'nullable|date',
            'subtotal'    => 'required|numeric|min:0',
            'tax_amount'  => 'nullable|numeric|min:0',
            'notes'       => 'nullable|string',
        ]);
        $data['tax_amount']   = $data['tax_amount'] ?? 0;
        $data['total_amount'] = $data['subtotal'] + $data['tax_amount'];
        $bill->update($data);
        return redirect()->route('bills.show', $bill)->with('success', 'Bill updated.');
    }

    public function destroy(Bill $bill)
    {
        if ($bill->payments()->count()) {
            return back()->with('error', 'Cannot delete a bill with existing payments.');
        }
        $bill->delete();
        return back()->with('success', 'Bill deleted.');
    }
}
