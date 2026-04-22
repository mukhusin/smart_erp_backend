<?php
namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryCategory;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class InventoryCategoryController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $categories = InventoryCategory::where('company_id', $this->ctx->id())
            ->withCount('items')->latest()->get();
        return view('inventory.categories.index', compact('categories'));
    }

    public function create()
    {
        return view('inventory.categories.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:150',
            'description' => 'nullable|string',
        ]);
        $data['company_id'] = $this->ctx->id();
        InventoryCategory::create($data);
        return redirect()->route('inventory.categories.index')->with('success', 'Category created.');
    }

    public function edit(InventoryCategory $category)
    {
        return view('inventory.categories.edit', compact('category'));
    }

    public function update(Request $request, InventoryCategory $category)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:150',
            'description' => 'nullable|string',
        ]);
        $category->update($data);
        return redirect()->route('inventory.categories.index')->with('success', 'Category updated.');
    }

    public function destroy(InventoryCategory $category)
    {
        if ($category->items()->count()) {
            return back()->with('error', 'Cannot delete a category with items.');
        }
        $category->delete();
        return back()->with('success', 'Category deleted.');
    }
}
