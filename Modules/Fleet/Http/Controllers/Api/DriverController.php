<?php

namespace Modules\Fleet\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmpLeave;
use App\Models\Employee;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Fleet\Actions\ManageDrivers;
use Modules\Fleet\Models\Driver;
use App\Models\EmployeeLicence;
use Modules\Fleet\Models\FleetUnit;
use Modules\Fleet\Support\FleetAccess;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Fleet: drivers
 */
class DriverController extends Controller
{
    public function __construct(private readonly FleetAccess $access, private readonly ManageDrivers $drivers) {}

    /**
     * Drivers (HR employees) with their current licence and vehicle.
     *
     * @response array{data: list<array{id: int, employee_id: int, name: string, employee_no: string|null, department: string|null, phone: string|null, status: string, status_label: string, licence_number: string|null, classes: list<string>, expires_on: string|null, days_left: int|null, vehicle: string|null, on_leave: bool}>}
     */
    #[QueryParameter('unit_id', description: 'Only drivers eligible for this unit.', type: 'integer')]
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $drivers = Driver::with(['employee.department', 'licences', 'assignments' => fn ($q) => $q->whereNull('ended_on')->with('unit')])->get();
        if ($unitId = $request->query('unit_id')) {
            $unit = FleetUnit::with('type')->findOrFail($unitId);
            $drivers = $drivers->filter(fn (Driver $d) => ! $this->drivers->ineligible($d, $unit));
        }
        $leave = $this->onLeave($drivers->pluck('employee_id')->all());

        return response()->json(['data' => $drivers->sortBy(fn ($d) => $d->name())->map(function (Driver $d) use ($leave) {
            $l = $d->currentLicence();

            return [
                'id' => $d->id, 'employee_id' => $d->employee_id, 'name' => $d->name(), 'employee_no' => $d->employee?->employee_no, 'department' => $d->employee?->department?->name,
                'phone' => $d->employee?->phone_number, 'status' => $d->employee?->employment_status === 'terminated' ? 'left' : $d->status,
                'status_label' => $d->employee?->employment_status === 'terminated' ? 'Left the organisation' : (Driver::STATUSES[$d->status] ?? $d->status),
                'licence_number' => $l?->licence_no, 'classes' => $l?->classes ?? [], 'expires_on' => $l?->end_date->toDateString(),
                'days_left' => $l ? (int) today()->diffInDays($l->end_date, false) : null,
                'vehicle' => $d->assignments->map(fn ($a) => $a->unit?->label())->filter()->join(', ') ?: null, 'on_leave' => in_array($d->employee_id, $leave, true),
            ];
        })->values()]);
    }

    /**
     * A driver with licences (history) and assignments.
     *
     * @response array{data: array{id: int, employee_id: int, name: string, employee_no: string|null, department: string|null, position: string|null, phone: string|null, employment_status: string|null, status: string, status_label: string, notes: string|null, on_leave: bool, licences: list<array{id: int, number: string, classes: list<string>, issued_on: string|null, expires_on: string, days_left: int, is_current: bool, has_document: bool}>, assignments: list<array{id: int, unit_id: int, unit: string, starts_on: string, ends_on: string|null, ended_on: string|null}>, can: array{manage: bool}}}
     */
    public function show(Request $request, Driver $driver): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $driver->load(['employee.department', 'employee.jobPosition', 'licences', 'assignments.unit']);
        $e = $driver->employee;

        return response()->json(['data' => [
            'id' => $driver->id, 'employee_id' => $driver->employee_id, 'name' => $driver->name(), 'employee_no' => $e?->employee_no, 'department' => $e?->department?->name,
            'position' => $e?->jobPosition?->title, 'phone' => $e?->phone_number, 'employment_status' => $e?->employment_status, 'status' => $driver->status,
            'status_label' => Driver::STATUSES[$driver->status] ?? $driver->status, 'notes' => $driver->notes, 'on_leave' => in_array($driver->employee_id, $this->onLeave([$driver->employee_id]), true),
            'licences' => $driver->licences->values()->map(fn (EmployeeLicence $l, $i) => ['id' => $l->id, 'number' => (string) $l->licence_no, 'classes' => $l->classes ?? [],
                'issued_on' => $l->start_date?->toDateString(), 'expires_on' => $l->end_date->toDateString(), 'days_left' => (int) today()->diffInDays($l->end_date, false),
                'is_current' => $i === 0, 'has_document' => (bool) $l->file_path])->values(),
            'assignments' => $driver->assignments->map(fn ($a) => ['id' => $a->id, 'unit_id' => $a->fleet_unit_id, 'unit' => (string) $a->unit?->label(), 'starts_on' => $a->starts_on->toDateString(),
                'ends_on' => $a->ends_on?->toDateString(), 'ended_on' => $a->ended_on?->toDateString()])->values(),
            'can' => ['manage' => $this->access->manages($request->user())],
        ]]);
    }

    /**
     * Current employees to add as drivers.
     *
     * @response array{data: list<array{id: int, name: string, detail: string|null}>}
     */
    #[QueryParameter('search', type: 'string')]
    public function employees(Request $request): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $s = trim((string) $request->query('search'));
        $taken = Driver::pluck('employee_id');
        $rows = Employee::with('department')->where('employment_status', '!=', 'terminated')->whereNotIn('id', $taken)
            ->when($s, fn ($q) => $q->where(fn ($q) => $q->where('fname', 'like', "%{$s}%")->orWhere('lname', 'like', "%{$s}%")->orWhere('employee_no', 'like', "%{$s}%")))
            ->orderBy('fname')->limit(20)->get();

        return response()->json(['data' => $rows->map(fn ($e) => ['id' => $e->id, 'name' => $e->full_name, 'detail' => trim(($e->employee_no ?? '').' · '.($e->department?->name ?? ''), ' ·')])->values()]);
    }

    /** @response array{data: array{id: int}} */
    public function store(Request $request): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $d = $this->drivers->save(null, $data, $request->user());

        return response()->json(['data' => ['id' => $d->id]], 201);
    }

    /** @response array{data: array{id: int}} */
    public function update(Request $request, Driver $driver): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            /** active, suspended or left. */
            'status' => ['required', 'in:'.implode(',', array_keys(Driver::STATUSES))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $d = $this->drivers->save($driver, $data, $request->user());

        return response()->json(['data' => ['id' => $d->id]]);
    }

    /** @response array{message: string} */
    public function licence(Request $request, Driver $driver): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $data = $request->validate([
            'number' => ['required', 'string', 'max:100'],
            /** Classes printed on the licence, e.g. B, C, D. */
            'classes' => ['required', 'array', 'min:1', 'max:20'],
            'classes.*' => ['string', 'max:5'],
            'issued_on' => ['required', 'date'],
            'expires_on' => ['required', 'date'],
            /** PDF, JPG or PNG, up to 5 MB. */
            'document' => ['nullable', 'file', ...\Modules\Hr\Support\EmployeeFiles::DOCUMENT_RULES],
        ]);
        $this->drivers->addLicence($driver, collect($data)->except('document')->all(), $request->file('document'), $request->user());

        return response()->json(['message' => 'Licence saved.']);
    }

    public function licenceFile(Request $request, int $licence): StreamedResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $l = EmployeeLicence::whereHas('employee')->whereHas('professionalLicence', fn ($q) => $q->where('is_driving', true))->findOrFail($licence);

        return \Modules\Hr\Support\EmployeeFiles::response($l->file_path, $l->file_name);
    }

    /** @return list<int> */
    private function onLeave(array $employeeIds): array
    {
        return EmpLeave::whereIn('employee_id', $employeeIds)->whereIn('status', [EmpLeave::APPROVED, EmpLeave::ON_LEAVE])
            ->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())->pluck('employee_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }
}
