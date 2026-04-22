<?php
namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetLocation;
use App\Models\Department;
use App\Models\Employee;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class AssetController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = Asset::where('company_id', $this->ctx->id())
            ->with('category', 'location', 'department', 'employee');
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('category_id')) {
            $query->where('asset_category_id', $request->category_id);
        }
        $assets     = $query->latest()->paginate(25);
        $categories = AssetCategory::where('company_id', $this->ctx->id())->orderBy('name')->get();
        return view('assets.index', compact('assets', 'categories'));
    }

    public function create()
    {
        $categories  = AssetCategory::where('company_id', $this->ctx->id())->orderBy('name')->get();
        $locations   = AssetLocation::where('company_id', $this->ctx->id())->orderBy('name')->get();
        $departments = Department::where('company_id', $this->ctx->id())->whereNull('parent_id')->orderBy('name')->get();
        $employees   = Employee::where('company_id', $this->ctx->id())->orderBy('fname')->get();
        return view('assets.create', compact('categories', 'locations', 'departments', 'employees'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                     => 'required|string|max:150',
            'asset_category_id'        => 'nullable|exists:asset_categories,id',
            'department_id'            => 'nullable|exists:departments,id',
            'asset_location_id'        => 'nullable|exists:asset_locations,id',
            'employee_id'              => 'nullable|exists:employees,id',
            'model'                    => 'nullable|string|max:100',
            'manufacturer'             => 'nullable|string|max:100',
            'serial_number'            => 'nullable|string|max:100',
            'barcode'                  => 'nullable|string|max:100',
            'cost'                     => 'nullable|numeric|min:0',
            'purchase_date'            => 'nullable|date',
            'warranty_expiration_date' => 'nullable|date',
            'status'                   => 'required|in:available,in-use,maintenance,disposed',
            'description'              => 'nullable|string',
        ]);
        $data['company_id'] = $this->ctx->id();
        Asset::create($data);
        return redirect()->route('assets.index')->with('success', 'Asset created.');
    }

    public function show(Asset $asset)
    {
        $asset->load('category', 'location', 'department', 'employee');
        return view('assets.show', compact('asset'));
    }

    public function edit(Asset $asset)
    {
        $categories  = AssetCategory::where('company_id', $this->ctx->id())->orderBy('name')->get();
        $locations   = AssetLocation::where('company_id', $this->ctx->id())->orderBy('name')->get();
        $departments = Department::where('company_id', $this->ctx->id())->whereNull('parent_id')->orderBy('name')->get();
        $employees   = Employee::where('company_id', $this->ctx->id())->orderBy('fname')->get();
        return view('assets.edit', compact('asset', 'categories', 'locations', 'departments', 'employees'));
    }

    public function update(Request $request, Asset $asset)
    {
        $data = $request->validate([
            'name'                     => 'required|string|max:150',
            'asset_category_id'        => 'nullable|exists:asset_categories,id',
            'department_id'            => 'nullable|exists:departments,id',
            'asset_location_id'        => 'nullable|exists:asset_locations,id',
            'employee_id'              => 'nullable|exists:employees,id',
            'model'                    => 'nullable|string|max:100',
            'manufacturer'             => 'nullable|string|max:100',
            'serial_number'            => 'nullable|string|max:100',
            'barcode'                  => 'nullable|string|max:100',
            'cost'                     => 'nullable|numeric|min:0',
            'purchase_date'            => 'nullable|date',
            'warranty_expiration_date' => 'nullable|date',
            'status'                   => 'required|in:available,in-use,maintenance,disposed',
            'description'              => 'nullable|string',
        ]);
        $asset->update($data);
        return redirect()->route('assets.show', $asset)->with('success', 'Asset updated.');
    }

    public function destroy(Asset $asset)
    {
        $asset->delete();
        return back()->with('success', 'Asset deleted.');
    }
}
