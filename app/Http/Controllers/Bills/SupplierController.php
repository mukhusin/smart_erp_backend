<?php
namespace App\Http\Controllers\Bills;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $suppliers = Supplier::where('company_id', $this->ctx->id())
            ->withCount('bills')->latest()->get();
        return view('bills.suppliers.index', compact('suppliers'));
    }

    public function create()
    {
        return view('bills.suppliers.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:150',
            'code'       => 'nullable|string|max:30',
            'phone'      => 'nullable|string|max:30',
            'email'      => 'nullable|email|max:150',
            'address'    => 'nullable|string',
            'tax_number' => 'nullable|string|max:50',
        ]);
        $data['company_id'] = $this->ctx->id();
        Supplier::create($data);
        return redirect()->route('bills.suppliers.index')->with('success', 'Supplier created.');
    }

    public function show(Supplier $supplier)
    {
        $supplier->load(['bills' => fn($q) => $q->latest()->take(20)]);
        return view('bills.suppliers.show', compact('supplier'));
    }

    public function edit(Supplier $supplier)
    {
        return view('bills.suppliers.edit', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:150',
            'code'       => 'nullable|string|max:30',
            'phone'      => 'nullable|string|max:30',
            'email'      => 'nullable|email|max:150',
            'address'    => 'nullable|string',
            'tax_number' => 'nullable|string|max:50',
            'is_active'  => 'boolean',
        ]);
        $supplier->update($data);
        return redirect()->route('bills.suppliers.index')->with('success', 'Supplier updated.');
    }

    public function destroy(Supplier $supplier)
    {
        if ($supplier->bills()->count()) {
            return back()->with('error', 'Cannot delete a supplier with existing bills.');
        }
        $supplier->delete();
        return back()->with('success', 'Supplier deleted.');
    }
}
