<?php
namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceLog;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class MaintenanceController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = VehicleMaintenanceLog::with('vehicle', 'doneBy')
            ->whereHas('vehicle', fn($q) => $q->where('company_id', $this->ctx->id()));
        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }
        $logs     = $query->latest('date')->paginate(25);
        $vehicles = Vehicle::where('company_id', $this->ctx->id())->orderBy('plate_number')->get();
        return view('fleet.maintenance.index', compact('logs', 'vehicles'));
    }

    public function create(Request $request)
    {
        $vehicles  = Vehicle::where('company_id', $this->ctx->id())->where('status', '!=', 'disposed')->orderBy('plate_number')->get();
        $employees = Employee::where('company_id', $this->ctx->id())->orderBy('fname')->get();
        $vehicle   = $request->filled('vehicle_id') ? Vehicle::find($request->vehicle_id) : null;
        return view('fleet.maintenance.create', compact('vehicles', 'employees', 'vehicle'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'vehicle_id'       => 'required|exists:vehicles,id',
            'date'             => 'required|date',
            'type'             => 'required|in:routine,repair,inspection,tyre,battery,other',
            'description'      => 'nullable|string',
            'cost'             => 'nullable|numeric|min:0',
            'service_provider' => 'nullable|string|max:150',
            'next_service_date'=> 'nullable|date',
            'done_by'          => 'nullable|exists:employees,id',
        ]);
        VehicleMaintenanceLog::create($data);
        return redirect()->route('fleet.maintenance.index')->with('success', 'Maintenance log added.');
    }

    public function show(VehicleMaintenanceLog $maintenance)
    {
        $maintenance->load('vehicle', 'doneBy');
        return view('fleet.maintenance.show', compact('maintenance'));
    }

    public function destroy(VehicleMaintenanceLog $maintenance)
    {
        $maintenance->delete();
        return back()->with('success', 'Maintenance log deleted.');
    }
}
