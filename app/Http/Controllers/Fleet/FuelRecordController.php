<?php
namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\FuelRecord;
use App\Models\Vehicle;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class FuelRecordController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = FuelRecord::with('vehicle', 'filledBy')
            ->whereHas('vehicle', fn($q) => $q->where('company_id', $this->ctx->id()));
        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }
        $records  = $query->latest('date')->paginate(25);
        $vehicles = Vehicle::where('company_id', $this->ctx->id())->orderBy('plate_number')->get();
        return view('fleet.fuel.index', compact('records', 'vehicles'));
    }

    public function create(Request $request)
    {
        $vehicles  = Vehicle::where('company_id', $this->ctx->id())->where('status', '!=', 'disposed')->orderBy('plate_number')->get();
        $employees = Employee::where('company_id', $this->ctx->id())->orderBy('fname')->get();
        $vehicle   = $request->filled('vehicle_id') ? Vehicle::find($request->vehicle_id) : null;
        return view('fleet.fuel.create', compact('vehicles', 'employees', 'vehicle'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'vehicle_id'      => 'required|exists:vehicles,id',
            'date'            => 'required|date',
            'liters'          => 'required|numeric|min:0.01',
            'cost_per_liter'  => 'nullable|numeric|min:0',
            'total_cost'      => 'nullable|numeric|min:0',
            'odometer_reading'=> 'nullable|integer|min:0',
            'filled_by'       => 'nullable|exists:employees,id',
            'notes'           => 'nullable|string',
        ]);
        if (empty($data['total_cost']) && !empty($data['cost_per_liter'])) {
            $data['total_cost'] = $data['liters'] * $data['cost_per_liter'];
        }
        FuelRecord::create($data);
        return redirect()->route('fleet.fuel.index')->with('success', 'Fuel record added.');
    }

    public function show(FuelRecord $fuel)
    {
        $fuel->load('vehicle', 'filledBy');
        return view('fleet.fuel.show', compact('fuel'));
    }

    public function destroy(FuelRecord $fuel)
    {
        $fuel->delete();
        return back()->with('success', 'Fuel record deleted.');
    }
}
