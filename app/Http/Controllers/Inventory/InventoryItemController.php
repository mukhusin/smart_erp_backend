<?php
namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class InventoryItemController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = InventoryItem::where('company_id', $this->ctx->id())->with('category');
        if ($request->filled('category_id')) {
            $query->where('inventory_category_id', $request->category_id);
        }
        if ($request->boolean('low_stock')) {
            $query->whereColumn('quantity_on_hand', '<=', 'minimum_stock');
        }
        $items      = $query->orderBy('name')->paginate(25);
        $categories = InventoryCategory::where('company_id', $this->ctx->id())->orderBy('name')->get();
        return view('inventory.items.index', compact('items', 'categories'));
    }

    public function create()
    {
        $categories = InventoryCategory::where('company_id', $this->ctx->id())->orderBy('name')->get();
        return view('inventory.items.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'inventory_category_id' => 'nullable|exists:inventory_categories,id',
            'code'                  => 'nullable|string|max:60',
            'name'                  => 'required|string|max:200',
            'description'           => 'nullable|string',
            'unit'                  => 'nullable|string|max:30',
            'unit_cost'             => 'required|numeric|min:0',
            'quantity_on_hand'      => 'required|numeric|min:0',
            'minimum_stock'         => 'required|numeric|min:0',
        ]);
        $data['company_id'] = $this->ctx->id();
        InventoryItem::create($data);
        return redirect()->route('inventory.items.index')->with('success', 'Item created.');
    }

    public function show(InventoryItem $item)
    {
        $item->load('category', 'movements');
        return view('inventory.items.show', compact('item'));
    }

    public function edit(InventoryItem $item)
    {
        $categories = InventoryCategory::where('company_id', $this->ctx->id())->orderBy('name')->get();
        return view('inventory.items.edit', compact('item', 'categories'));
    }

    public function update(Request $request, InventoryItem $item)
    {
        $data = $request->validate([
            'inventory_category_id' => 'nullable|exists:inventory_categories,id',
            'code'                  => 'nullable|string|max:60',
            'name'                  => 'required|string|max:200',
            'description'           => 'nullable|string',
            'unit'                  => 'nullable|string|max:30',
            'unit_cost'             => 'required|numeric|min:0',
            'minimum_stock'         => 'required|numeric|min:0',
            'is_active'             => 'boolean',
        ]);
        $item->update($data);
        return redirect()->route('inventory.items.show', $item)->with('success', 'Item updated.');
    }

    public function destroy(InventoryItem $item)
    {
        $item->delete();
        return back()->with('success', 'Item deleted.');
    }
}
