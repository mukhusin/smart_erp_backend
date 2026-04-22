<?php
namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = Vehicle::where('company_id', $this->ctx->id())
            ->with('currentDriver');
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        $vehicles = $query->latest()->paginate(20);
        return view('fleet.vehicles.index', compact('vehicles'));
    }

    public function create()
    {
        $employees = Employee::where('company_id', $this->ctx->id())->orderBy('fname')->get();
        return view('fleet.vehicles.create', compact('employees'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'plate_number'      => 'required|string|max:30|unique:vehicles,plate_number',
            'make'              => 'required|string|max:80',
            'model'             => 'required|string|max:80',
            'year'              => 'nullable|integer|min:1900|max:2100',
            'color'             => 'nullable|string|max:40',
            'type'              => 'required|in:sedan,suv,truck,bus,van,motorcycle,other',
            'status'            => 'required|in:available,in-use,maintenance,disposed',
            'current_driver_id' => 'nullable|exists:employees,id',
            'purchase_date'     => 'nullable|date',
            'purchase_cost'     => 'nullable|numeric|min:0',
            'insurance_expiry'  => 'nullable|date',
            'inspection_expiry' => 'nullable|date',
            'description'       => 'nullable|string',
        ]);
        $data['company_id'] = $this->ctx->id();
        Vehicle::create($data);
        return redirect()->route('fleet.vehicles.index')->with('success', 'Vehicle added.');
    }

    public function show(Vehicle $vehicle)
    {
        $vehicle->load('currentDriver', 'fuelRecords', 'maintenanceLogs');
        return view('fleet.vehicles.show', compact('vehicle'));
    }

    public function edit(Vehicle $vehicle)
    {
        $employees = Employee::where('company_id', $this->ctx->id())->orderBy('fname')->get();
        return view('fleet.vehicles.edit', compact('vehicle', 'employees'));
    }

    public function update(Request $request, Vehicle $vehicle)
    {
        $data = $request->validate([
            'plate_number'      => 'required|string|max:30|unique:vehicles,plate_number,' . $vehicle->id,
            'make'              => 'required|string|max:80',
            'model'             => 'required|string|max:80',
            'year'              => 'nullable|integer|min:1900|max:2100',
            'color'             => 'nullable|string|max:40',
            'type'              => 'required|in:sedan,suv,truck,bus,van,motorcycle,other',
            'status'            => 'required|in:available,in-use,maintenance,disposed',
            'current_driver_id' => 'nullable|exists:employees,id',
            'purchase_date'     => 'nullable|date',
            'purchase_cost'     => 'nullable|numeric|min:0',
            'insurance_expiry'  => 'nullable|date',
            'inspection_expiry' => 'nullable|date',
            'description'       => 'nullable|string',
        ]);
        $vehicle->update($data);
        return redirect()->route('fleet.vehicles.show', $vehicle)->with('success', 'Vehicle updated.');
    }

    public function destroy(Vehicle $vehicle)
    {
        $vehicle->delete();
        return back()->with('success', 'Vehicle deleted.');
    }
}
