<?php
namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Services\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = PurchaseOrder::where('company_id', $this->ctx->id())
            ->with('supplier', 'purchaseRequest', 'items');
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        $orders = $query->latest('date')->paginate(20);
        return view('procurement.orders.index', compact('orders'));
    }

    public function create(Request $request)
    {
        $suppliers  = Supplier::where('company_id', $this->ctx->id())->where('is_active', true)->orderBy('name')->get();
        $prs        = PurchaseRequest::where('company_id', $this->ctx->id())
            ->whereIn('status', ['approved'])->with('items')->latest()->get();
        $selectedPr = $request->filled('pr_id') ? PurchaseRequest::with('items')->find($request->pr_id) : null;
        return view('procurement.orders.create', compact('suppliers', 'prs', 'selectedPr'));
    }

    public function store(Request $req)
    {
        $req->validate([
            'supplier_id'         => 'required|exists:suppliers,id',
            'purchase_request_id' => 'nullable|exists:purchase_requests,id',
            'po_number'           => 'nullable|string|max:60',
            'date'                => 'required|date',
            'notes'               => 'nullable|string',
            'items'               => 'required|array|min:1',
            'items.*.item_name'   => 'required|string|max:200',
            'items.*.quantity'    => 'required|numeric|min:0.01',
            'items.*.unit'        => 'nullable|string|max:30',
            'items.*.unit_price'  => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($req) {
            $total = collect($req->items)->sum(fn($i) => $i['quantity'] * $i['unit_price']);
            $po = PurchaseOrder::create([
                'company_id'          => $this->ctx->id(),
                'supplier_id'         => $req->supplier_id,
                'purchase_request_id' => $req->purchase_request_id,
                'po_number'           => $req->po_number,
                'date'                => $req->date,
                'notes'               => $req->notes,
                'total_amount'        => $total,
                'status'              => 'draft',
            ]);
            foreach ($req->items as $item) {
                $po->items()->create([
                    'item_name'   => $item['item_name'],
                    'quantity'    => $item['quantity'],
                    'unit'        => $item['unit'] ?? null,
                    'unit_price'  => $item['unit_price'],
                    'total_price' => $item['quantity'] * $item['unit_price'],
                ]);
            }
            if ($req->purchase_request_id) {
                PurchaseRequest::where('id', $req->purchase_request_id)->update(['status' => 'ordered']);
            }
        });

        return redirect()->route('procurement.orders.index')->with('success', 'Purchase order created.');
    }

    public function show(PurchaseOrder $order)
    {
        $order->load('supplier', 'purchaseRequest', 'items');
        return view('procurement.orders.show', compact('order'));
    }

    public function edit(PurchaseOrder $order)
    {
        if ($order->status !== 'draft') {
            return back()->with('error', 'Only draft orders can be edited.');
        }
        $order->load('items');
        $suppliers = Supplier::where('company_id', $this->ctx->id())->where('is_active', true)->orderBy('name')->get();
        return view('procurement.orders.edit', compact('order', 'suppliers'));
    }

    public function update(Request $req, PurchaseOrder $order)
    {
        if ($order->status !== 'draft') {
            return back()->with('error', 'Only draft orders can be edited.');
        }
        $req->validate([
            'supplier_id'        => 'required|exists:suppliers,id',
            'date'               => 'required|date',
            'notes'              => 'nullable|string',
            'items'              => 'required|array|min:1',
            'items.*.item_name'  => 'required|string|max:200',
            'items.*.quantity'   => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($req, $order) {
            $total = collect($req->items)->sum(fn($i) => $i['quantity'] * $i['unit_price']);
            $order->update([
                'supplier_id'  => $req->supplier_id,
                'date'         => $req->date,
                'notes'        => $req->notes,
                'total_amount' => $total,
            ]);
            $order->items()->delete();
            foreach ($req->items as $item) {
                $order->items()->create([
                    'item_name'   => $item['item_name'],
                    'quantity'    => $item['quantity'],
                    'unit'        => $item['unit'] ?? null,
                    'unit_price'  => $item['unit_price'],
                    'total_price' => $item['quantity'] * $item['unit_price'],
                ]);
            }
        });

        return redirect()->route('procurement.orders.show', $order)->with('success', 'Order updated.');
    }

    public function destroy(PurchaseOrder $order)
    {
        if ($order->status !== 'draft') {
            return back()->with('error', 'Only draft orders can be deleted.');
        }
        $order->delete();
        return back()->with('success', 'Purchase order deleted.');
    }
}
