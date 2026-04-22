<?php
namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Models\AssetCategory;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class AssetCategoryController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $categories = AssetCategory::where('company_id', $this->ctx->id())
            ->withCount('assets')->latest()->get();
        return view('assets.categories.index', compact('categories'));
    }

    public function create()
    {
        return view('assets.categories.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:150',
            'description' => 'nullable|string',
        ]);
        $data['company_id'] = $this->ctx->id();
        AssetCategory::create($data);
        return redirect()->route('assets.categories.index')->with('success', 'Category created.');
    }

    public function edit(AssetCategory $category)
    {
        return view('assets.categories.edit', compact('category'));
    }

    public function update(Request $request, AssetCategory $category)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:150',
            'description' => 'nullable|string',
        ]);
        $category->update($data);
        return redirect()->route('assets.categories.index')->with('success', 'Category updated.');
    }

    public function destroy(AssetCategory $category)
    {
        if ($category->assets()->count()) {
            return back()->with('error', 'Cannot delete a category with assets.');
        }
        $category->delete();
        return back()->with('success', 'Category deleted.');
    }
}
