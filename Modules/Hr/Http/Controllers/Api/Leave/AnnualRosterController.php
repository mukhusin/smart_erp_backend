<?php

namespace Modules\Hr\Http\Controllers\Api\Leave;

use App\Http\Controllers\Controller;
use App\Models\AnnualLeaveRoster;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Roster;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Services\AccessService;
use Modules\Hr\Actions\ManageAnnualRoster;
use Modules\Hr\Support\LeaveBalances;
use Modules\Hr\Support\LeavePeriods;
use Modules\Hr\Support\RosterAccess;

/**
 * Annual leave rosters: when each employee of a department or section takes annual leave in a leave year.
 *
 * @tags Leave: Annual leave roster
 */
class AnnualRosterController extends Controller
{
    private const MANAGE = 'leave.annual-roster.manage';

    public function __construct(private readonly ManageAnnualRoster $plans, private readonly RosterAccess $access) {}

    /**
     * Annual leave rosters the user can see for a leave year (default: this leave year), with the departments they can plan for.
     *
     * @response array{data: list<array{id: int, title: string, department: array{id: int, name: string}|null, section: array{id: int, name: string}|null, year: int, from_date: string, to_date: string, status: string, status_label: string, planned: int, employees: int, can_manage: bool}>, meta: array{year: int, departments: list<array{id: int, name: string, parent: string|null}>}}
     */
    #[QueryParameter('year', type: 'integer')]
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->canList($request), 403);
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']])['year'] ?? app(LeavePeriods::class)->companyYear());
        $rows = $this->access->apply(Roster::where('type', ManageAnnualRoster::TYPE)->where('roster_year', (string) $year), $request->user())
            ->with(['department:id,name', 'section:id,name'])->withCount(['annualLeaves as planned', 'annualLeaves as employees' => fn ($q) => $q->select(\DB::raw('count(distinct employee_id)'))])
            ->orderBy('title')->get();
        $departments = app(AccessService::class)->can($request->user(), self::MANAGE)
            ? $this->access->manageable($request->user())->with('parent:id,name')->get()->sortBy(fn ($d) => ($d->parent?->name ?? $d->name).' '.($d->parent ? $d->name : ''))->values()
                ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'parent' => $d->parent?->name])
            : collect();

        return response()->json(['data' => $rows->map(fn ($r) => $this->row($r, $request)), 'meta' => ['year' => $year, 'departments' => $departments]]);
    }

    /**
     * Create the annual leave roster of a department or section for a leave year.
     *
     * @response array{data: array{id: int, title: string, department: array{id: int, name: string}|null, section: array{id: int, name: string}|null, year: int, from_date: string, to_date: string, status: string, status_label: string, planned: int, employees: int, can_manage: bool}}
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['department_id' => ['required', 'integer'], 'section_id' => ['nullable', 'integer'], 'year' => ['nullable', 'integer', 'min:2000', 'max:2100']]);
        $department = Department::findOrFail($data['department_id']);
        abort_unless($this->access->canManage($request->user(), $department->parent_id ?: $department->id, $department->parent_id ? $department->id : ($data['section_id'] ?? null), self::MANAGE), 403, 'You cannot plan leave for this department.');
        $roster = $this->plans->create($department->id, $data['section_id'] ?? null, $data['year'] ?? null, $request->user());

        return response()->json(['data' => $this->row($roster->load(['department', 'section'])->loadCount(['annualLeaves as planned', 'annualLeaves as employees']), $request)], 201);
    }

    /**
     * A roster with its employees (annual leave balance, plans) and how many are planned on leave each month.
     *
     * @response array{data: array{roster: array{id: int, title: string, department: array{id: int, name: string}|null, section: array{id: int, name: string}|null, year: int, from_date: string, to_date: string, status: string, status_label: string, planned: int, employees: int, can_manage: bool}, employees: list<array{id: int, name: string, employee_no: string|null, position: string|null, eligible: bool, reason: string|null, available: float, planned_days: int, period_label: string, plans: list<array{id: int, start_date: string, end_date: string, days: int, status: string, status_label: string, description: string|null}>}>, months: list<array{month: string, on_leave: int}>}}
     */
    public function show(Request $request, Roster $roster): JsonResponse
    {
        $this->authorizeView($request, $roster);
        $roster->load(['department', 'section'])->loadCount(['annualLeaves as planned', 'annualLeaves as employees' => fn ($q) => $q->select(\DB::raw('count(distinct employee_id)'))]);
        $type = $this->plans->annualType();
        $balances = app(LeaveBalances::class);
        $periods = app(LeavePeriods::class);
        $plans = $roster->annualLeaves()->orderBy('start_date')->get()->groupBy('employee_id');
        $employees = $this->plans->employees($roster)->load('jobPosition')->sortBy('fname')->values()->map(function (Employee $e) use ($plans, $balances, $type, $periods, $roster) {
            $year = $periods->yearOf($e, $type, $roster->from_date);
            $b = $balances->for($e, $type, $year);
            $mine = $plans->get($e->id, collect());

            return [
                'id' => $e->id, 'name' => $e->full_name, 'employee_no' => $e->employee_no, 'position' => $e->jobPosition?->title,
                'eligible' => $b['eligible'], 'reason' => $b['reason'], 'available' => $b['available'], 'planned_days' => (int) $mine->where('status', AnnualLeaveRoster::PLANNED)->sum('days'),
                'period_label' => $b['period_label'], 'plans' => $mine->map(fn ($p) => $this->plan($p))->values(),
            ];
        });
        $months = collect(range(0, 11))->map(function ($i) use ($roster) {
            $start = $roster->from_date->copy()->addMonthsNoOverflow($i)->startOfMonth();
            $end = $start->copy()->endOfMonth();

            return ['month' => $start->format('Y-m'), 'on_leave' => $roster->annualLeaves()->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->distinct()->count('employee_id')];
        });

        return response()->json(['data' => ['roster' => $this->row($roster, $request), 'employees' => $employees, 'months' => $months]]);
    }

    /**
     * Plan an employee's annual leave.
     *
     * @response array{data: array{id: int, start_date: string, end_date: string, days: int, status: string, status_label: string, description: string|null}}
     */
    public function storePlan(Request $request, Roster $roster): JsonResponse
    {
        $this->authorizeManage($request, $roster);

        return response()->json(['data' => $this->plan($this->plans->save($roster, $this->planData($request), $request->user()))], 201);
    }

    /**
     * Change a planned leave (not yet requested).
     *
     * @response array{data: array{id: int, start_date: string, end_date: string, days: int, status: string, status_label: string, description: string|null}}
     */
    public function updatePlan(Request $request, Roster $roster, AnnualLeaveRoster $plan): JsonResponse
    {
        $this->authorizeManage($request, $roster);
        abort_unless($plan->roster_id === $roster->id, 404);

        return response()->json(['data' => $this->plan($this->plans->save($roster, $this->planData($request), $request->user(), $plan))]);
    }

    /** Remove a planned leave (not yet requested). */
    public function destroyPlan(Request $request, Roster $roster, AnnualLeaveRoster $plan): Response
    {
        $this->authorizeManage($request, $roster);
        abort_unless($plan->roster_id === $roster->id, 404);
        $this->plans->remove($plan);

        return response()->noContent();
    }

    /**
     * Publish the roster: employees see their planned leave and are told.
     *
     * @response array{data: array{id: int, title: string, department: array{id: int, name: string}|null, section: array{id: int, name: string}|null, year: int, from_date: string, to_date: string, status: string, status_label: string, planned: int, employees: int, can_manage: bool}}
     */
    public function publish(Request $request, Roster $roster): JsonResponse
    {
        $this->authorizeManage($request, $roster);

        return response()->json(['data' => $this->row($this->plans->publish($roster)->load(['department', 'section'])->loadCount(['annualLeaves as planned', 'annualLeaves as employees']), $request)]);
    }

    /** Delete a roster whose leave has not been requested. */
    public function destroy(Request $request, Roster $roster): Response
    {
        $this->authorizeManage($request, $roster);
        $this->plans->delete($roster);

        return response()->noContent();
    }

    /**
     * My planned annual leave on published rosters.
     *
     * @response array{data: list<array{id: int, start_date: string, end_date: string, days: int, status: string, status_label: string, description: string|null}>}
     */
    public function mine(Request $request): JsonResponse
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();
        abort_unless($employee, 404, 'No employee record is linked to your account.');
        $rows = AnnualLeaveRoster::where('employee_id', $employee->id)->whereHas('roster', fn ($q) => $q->where('status', Roster::PUBLISHED))
            ->whereDate('end_date', '>=', now()->subMonths(3))->orderBy('start_date')->get();

        return response()->json(['data' => $rows->map(fn ($p) => $this->plan($p))->values()]);
    }

    private function planData(Request $request): array
    {
        return $request->validate([
            'employee_id' => ['required', 'integer'],
            'start_date' => ['required', 'date'],
            /** Days of annual leave, counted by the annual leave rule. */
            'days' => ['required', 'integer', 'min:1', 'max:366'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function plan(AnnualLeaveRoster $p): array
    {
        return ['id' => $p->id, 'start_date' => $p->start_date->toDateString(), 'end_date' => $p->end_date->toDateString(), 'days' => (int) $p->days,
            'status' => $p->status, 'status_label' => AnnualLeaveRoster::STATUS_LABELS[$p->status] ?? $p->status, 'description' => $p->description];
    }

    private function row(Roster $r, Request $request): array
    {
        return [
            'id' => $r->id, 'title' => $r->title,
            'department' => $r->department ? ['id' => $r->department->id, 'name' => $r->department->name] : null,
            'section' => $r->section ? ['id' => $r->section->id, 'name' => $r->section->name] : null,
            'year' => (int) $r->roster_year, 'from_date' => $r->from_date->toDateString(), 'to_date' => $r->to_date->toDateString(),
            'status' => $r->status, 'status_label' => $r->status === Roster::PUBLISHED ? 'Published' : 'Draft',
            'planned' => (int) ($r->planned ?? 0), 'employees' => (int) ($r->employees ?? 0),
            'can_manage' => $this->access->canManage($request->user(), $r->department_id, $r->section_id, self::MANAGE),
        ];
    }

    private function canList(Request $request): bool
    {
        $access = app(AccessService::class);

        return collect([self::MANAGE, 'leave.view', 'leave.department.view'])->contains(fn ($p) => $access->can($request->user(), $p));
    }

    private function authorizeView(Request $request, Roster $roster): void
    {
        abort_unless($roster->type === ManageAnnualRoster::TYPE, 404);
        $all = app(AccessService::class)->can($request->user(), 'leave.view');
        abort_unless($all || ($this->canList($request) && $this->access->covers($request->user(), $roster->department_id, $roster->section_id)), 404);
    }

    private function authorizeManage(Request $request, Roster $roster): void
    {
        abort_unless($roster->type === ManageAnnualRoster::TYPE, 404);
        abort_unless($this->access->canManage($request->user(), $roster->department_id, $roster->section_id, self::MANAGE), 403, 'You cannot plan leave for this department.');
    }
}
