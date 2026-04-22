<?php
namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Services\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockMovementController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = StockMovement::with('item.category')
            ->whereHas('item', fn($q) => $q->where('company_id', $this->ctx->id()));
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('item_id')) {
            $query->where('inventory_item_id', $request->item_id);
        }
        $movements = $query->latest('date')->paginate(25);
        $items = InventoryItem::where('company_id', $this->ctx->id())->where('is_active', true)->orderBy('name')->get();
        return view('inventory.movements.index', compact('movements', 'items'));
    }

    public function create(Request $request)
    {
        $items = InventoryItem::where('company_id', $this->ctx->id())->where('is_active', true)->orderBy('name')->get();
        $item  = $request->filled('item_id') ? InventoryItem::find($request->item_id) : null;
        return view('inventory.movements.create', compact('items', 'item'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'inventory_item_id' => 'required|exists:inventory_items,id',
            'type'              => 'required|in:in,out,adjustment',
            'quantity'          => 'required|numeric|min:0.01',
            'date'              => 'required|date',
            'reference'         => 'nullable|string|max:100',
            'notes'             => 'nullable|string',
        ]);
        $data['created_by'] = auth()->id();

        DB::transaction(function () use ($data) {
            StockMovement::create($data);
            $item = InventoryItem::find($data['inventory_item_id']);
            if ($data['type'] === 'in') {
                $item->increment('quantity_on_hand', $data['quantity']);
            } elseif ($data['type'] === 'out') {
                $item->decrement('quantity_on_hand', $data['quantity']);
            } else {
                $item->update(['quantity_on_hand' => $data['quantity']]);
            }
        });

        return redirect()->route('inventory.movements.index')->with('success', 'Stock movement recorded.');
    }

    public function show(StockMovement $movement)
    {
        $movement->load('item.category', 'creator');
        return view('inventory.movements.show', compact('movement'));
    }
}
