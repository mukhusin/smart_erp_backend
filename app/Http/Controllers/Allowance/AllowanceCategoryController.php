<?php

namespace App\Http\Controllers\Allowance;

use App\Http\Controllers\Controller;
use App\Models\AllowanceCategory;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class AllowanceCategoryController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $categories = AllowanceCategory::where('company_id', $this->ctx->id())
            ->orderBy('category')->paginate(25);
        return view('allowance.categories.index', compact('categories'));
    }

    public function create()
    {
        return view('allowance.categories.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category' => 'required|string|max:100',
            'code'     => 'required|string|max:20',
            'duration' => 'required|in:month,day',
        ]);

        AllowanceCategory::create(array_merge($data, [
            'company_id'    => $this->ctx->id(),
            'claimed'       => $request->boolean('claimed'),
            'deduct_leave'  => $request->boolean('deduct_leave'),
            'other_absence' => $request->boolean('other_absence'),
            'is_active'     => true,
        ]));

        return redirect()->route('allowance.categories.index')->with('success', 'Category created.');
    }

    public function edit(AllowanceCategory $category)
    {
        return view('allowance.categories.edit', compact('category'));
    }

    public function update(Request $request, AllowanceCategory $category)
    {
        $data = $request->validate([
            'category' => 'required|string|max:100',
            'code'     => 'required|string|max:20',
            'duration' => 'required|in:month,day',
        ]);

        $category->update(array_merge($data, [
            'claimed'       => $request->boolean('claimed'),
            'deduct_leave'  => $request->boolean('deduct_leave'),
            'other_absence' => $request->boolean('other_absence'),
            'is_active'     => $request->boolean('is_active'),
        ]));

        return redirect()->route('allowance.categories.index')->with('success', 'Category updated.');
    }

    public function destroy(AllowanceCategory $category)
    {
        $category->delete();
        return back()->with('success', 'Category deleted.');
    }
}
