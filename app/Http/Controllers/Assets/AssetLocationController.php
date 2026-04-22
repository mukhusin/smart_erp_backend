<?php
namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Models\AssetLocation;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class AssetLocationController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index()
    {
        $locations = AssetLocation::where('company_id', $this->ctx->id())
            ->withCount('assets')->latest()->get();
        return view('assets.locations.index', compact('locations'));
    }

    public function create()
    {
        return view('assets.locations.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:150',
            'description' => 'nullable|string',
        ]);
        $data['company_id'] = $this->ctx->id();
        AssetLocation::create($data);
        return redirect()->route('assets.locations.index')->with('success', 'Location created.');
    }

    public function edit(AssetLocation $location)
    {
        return view('assets.locations.edit', compact('location'));
    }

    public function update(Request $request, AssetLocation $location)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:150',
            'description' => 'nullable|string',
        ]);
        $location->update($data);
        return redirect()->route('assets.locations.index')->with('success', 'Location updated.');
    }

    public function destroy(AssetLocation $location)
    {
        if ($location->assets()->count()) {
            return back()->with('error', 'Cannot delete a location with assets.');
        }
        $location->delete();
        return back()->with('success', 'Location deleted.');
    }
}
