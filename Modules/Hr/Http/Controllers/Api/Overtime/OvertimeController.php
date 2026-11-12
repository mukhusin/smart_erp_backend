<?php

namespace Modules\Hr\Http\Controllers\Api\Overtime;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobPosition;
use App\Models\Overtime;
use App\Models\OvertimeRate;
use App\Services\CompanyContext;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Core\Services\AccessService;
use Modules\Hr\Actions\ManageOvertime;
use Modules\Hr\Http\Requests\OvertimeClaimRequest;
use Modules\Hr\Support\EmployeeScope;

/**
 * Overtime rates and claims. Only time outside the planned shift (roster or working hours) counts.
 *
 * @tags Overtime
 */
class OvertimeController extends Controller
{
    public function __construct(private readonly ManageOvertime $overtime) {}

    /**
     * Overtime rates, with the departments and positions to choose from.
     *
     * @response array{data: list<array{id: int, department: array{id: int, name: string}|null, section: array{id: int, name: string}|null, position: array{id: int, title: string}|null, unit: string, amount: float, is_active: bool}>, meta: array{departments: list<array{id: int, name: string, parent_id: int|null}>, positions: list<array{id: int, title: string}>}}
     */
    public function rates(): JsonResponse
    {
        return response()->json([
            'data' => OvertimeRate::with(['department', 'section', 'jobPosition'])->get()
                ->sortBy(fn ($r) => ($r->department?->name ?? '').' '.($r->section?->name ?? '').' '.($r->jobPosition?->title ?? ''))->values()->map(fn ($r) => $this->rate($r)),
            'meta' => [
                'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name', 'parent_id'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'parent_id' => $d->parent_id]),
                'positions' => JobPosition::where('is_active', true)->orderBy('title')->get(['id', 'title'])->map(fn ($p) => ['id' => $p->id, 'title' => $p->title]),
            ],
        ]);
    }

    /**
     * Add an overtime rate.
     *
     * @response array{data: array{id: int, department: array{id: int, name: string}|null, section: array{id: int, name: string}|null, position: array{id: int, title: string}|null, unit: string, amount: float, is_active: bool}}
     */
    public function storeRate(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->rate(OvertimeRate::create($this->rateData($request) + ['updated_by' => $request->user()->id])->load(['department', 'section', 'jobPosition']))], 201);
    }

    /**
     * Update an overtime rate. Claims already made keep their amount.
     *
     * @response array{data: array{id: int, department: array{id: int, name: string}|null, section: array{id: int, name: string}|null, position: array{id: int, title: string}|null, unit: string, amount: float, is_active: bool}}
     */
    public function updateRate(Request $request, OvertimeRate $rate): JsonResponse
    {
        $rate->update($this->rateData($request, $rate) + ['updated_by' => $request->user()->id]);

        return response()->json(['data' => $this->rate($rate->load(['department', 'section', 'jobPosition']))]);
    }

    /** Delete an overtime rate. */
    public function destroyRate(OvertimeRate $rate): Response
    {
        $rate->delete();

        return response()->noContent();
    }

    /**
     * Overtime claims of a month for the employees the user can see, with totals per employee.
     *
     * @response array{data: list<array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, date: string, from: string, to: string, overtime_minutes: int, duration: string, rate_unit: string|null, rate_amount: float|null, amount: float, task_title: string, description: string|null, planned: array<string, mixed>|null, status: string, status_label: string, source: string, waiting_for: string|null, decision_comment: string|null, can_withdraw: bool}>, totals: list<array{employee_id: int, name: string, minutes: int, amount: float}>, meta: array{month: string, total: float, can_manage: bool}}
     */
    #[QueryParameter('month', type: 'string', description: 'e.g. 2026-11; default this month')]
    #[QueryParameter('status', type: 'string')]
    #[QueryParameter('employee_id', type: 'integer')]
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->canBrowse($request), 403);
        $f = $request->validate(['month' => ['nullable', 'date_format:Y-m'], 'status' => ['nullable', 'in:pending,approved,rejected,cancelled'], 'employee_id' => ['nullable', 'integer']]);
        $month = $f['month'] ?? now()->format('Y-m');
        $from = Carbon::createFromFormat('Y-m-d', "$month-01")->startOfMonth();
        $rows = Overtime::with(['employee.department', 'workflow.steps', 'requester'])
            ->whereHas('employee', fn ($q) => app(EmployeeScope::class)->apply($q, $request->user()))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['employee_id'] ?? null, fn ($q, $e) => $q->where('employee_id', $e))
            ->whereDate('date_of_task_done', '>=', $from)->whereDate('date_of_task_done', '<=', $from->copy()->endOfMonth())
            ->orderByDesc('date_of_task_done')->orderByDesc('id')->get();
        $approved = $rows->where('status', 'approved');

        return response()->json([
            'data' => $rows->map(fn ($o) => $this->claimRow($o, true))->values(),
            'totals' => $approved->groupBy('employee_id')->map(fn ($g) => ['employee_id' => (int) $g->first()->employee_id, 'name' => $g->first()->employee?->full_name ?? '',
                'minutes' => (int) $g->sum('overtime_minutes'), 'amount' => round((float) $g->sum('amount'), 2)])->sortBy('name')->values(),
            'meta' => ['month' => $month, 'total' => round((float) $approved->sum('amount'), 2), 'can_manage' => app(AccessService::class)->can($request->user(), 'overtime.manage')],
        ]);
    }

    /**
     * A claim for approvers: times, planned shift, amount and whether the user can change the times.
     *
     * @response array{data: array{claim: array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, date: string, from: string, to: string, overtime_minutes: int, duration: string, rate_unit: string|null, rate_amount: float|null, amount: float, task_title: string, description: string|null, planned: array<string, mixed>|null, status: string, status_label: string, source: string, waiting_for: string|null, decision_comment: string|null, can_withdraw: bool}, can_change: bool}}
     */
    public function review(Request $request, Overtime $overtime): JsonResponse
    {
        $user = $request->user();
        $allowed = $this->overtime->canChange($overtime, $user) || $overtime->employee?->user_id === $user->id
            || ($this->canBrowse($request) && $overtime->employee && app(EmployeeScope::class)->allows($user, $overtime->employee))
            || ($overtime->workflow && $overtime->workflow->steps()->where('acted_by', $user->id)->exists());
        abort_unless($allowed, 404);

        return response()->json(['data' => ['claim' => $this->claimRow($overtime->load(['employee.department', 'workflow.steps']), true), 'can_change' => $this->overtime->canChange($overtime, $user)]]);
    }

    /** Change the times of a claim waiting for approval (HR or the current approver). */
    public function times(Request $request, Overtime $overtime): JsonResponse
    {
        $d = $request->validate(['start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i'], 'comment' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->claimRow($this->overtime->changeTimes($overtime, $d['start_time'], $d['end_time'], $request->user(), $d['comment'] ?? null)->load(['employee.department', 'workflow.steps']), true)]);
    }

    /** Claim overtime for an employee (head of department or HR). */
    public function storeFor(OvertimeClaimRequest $request, Employee $employee): JsonResponse
    {
        abort_unless(app(EmployeeScope::class)->allows($request->user(), $employee), 404);

        return response()->json(['data' => $this->claimRow($this->overtime->claim($employee, $request->validated(), $request->user(), 'manager')->load(['employee.department', 'workflow.steps']), true)], 201);
    }

    /**
     * My overtime claims for a month (default this month).
     *
     * @response array{data: list<array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, date: string, from: string, to: string, overtime_minutes: int, duration: string, rate_unit: string|null, rate_amount: float|null, amount: float, task_title: string, description: string|null, planned: array<string, mixed>|null, status: string, status_label: string, source: string, waiting_for: string|null, decision_comment: string|null, can_withdraw: bool}>, meta: array{month: string, total: float, minutes: int, can_claim: bool}}
     */
    #[QueryParameter('month', type: 'string')]
    public function mine(Request $request): JsonResponse
    {
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');
        $employee = $this->me($request);
        $from = Carbon::createFromFormat('Y-m-d', "$month-01")->startOfMonth();
        $rows = Overtime::with('workflow.steps')->where('employee_id', $employee->id)->whereDate('date_of_task_done', '>=', $from)->whereDate('date_of_task_done', '<=', $from->copy()->endOfMonth())
            ->orderByDesc('date_of_task_done')->get();
        $approved = $rows->where('status', 'approved');

        return response()->json([
            'data' => $rows->map(fn ($o) => $this->claimRow($o, false))->values(),
            'meta' => ['month' => $month, 'total' => round((float) $approved->sum('amount'), 2), 'minutes' => (int) $approved->sum('overtime_minutes'),
                'can_claim' => $this->overtime->eligible($employee) && $this->overtime->rateFor($employee) !== null],
        ]);
    }

    /**
     * How much of a period is overtime for me (outside my planned shift).
     *
     * @response array{data: array{from: string, to: string, total_minutes: int, planned_minutes: int, overtime_minutes: int, duration: string, day: string, day_label: string, shifts: list<array{label: string, start: string, end: string}>, amount: float|null}}
     */
    #[QueryParameter('date', type: 'string', required: true)]
    #[QueryParameter('start_time', type: 'string', required: true, example: '15:30')]
    #[QueryParameter('end_time', type: 'string', required: true, example: '19:00')]
    public function preview(Request $request): JsonResponse
    {
        $d = $request->validate(['date' => ['required', 'date'], 'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i']]);
        $employee = $this->me($request);
        $c = $this->overtime->compute($employee, $d['date'], $d['start_time'], $d['end_time']);
        $rate = $this->overtime->rateFor($employee);
        $amount = $rate ? ($rate->unit === 'shift' ? $rate->amount : round($c['overtime_minutes'] / 60 * $rate->amount, 2)) : null;

        return response()->json(['data' => [
            'from' => $c['from']->format('Y-m-d H:i'), 'to' => $c['to']->format('Y-m-d H:i'), 'total_minutes' => $c['total_minutes'], 'planned_minutes' => $c['planned_minutes'],
            'overtime_minutes' => $c['overtime_minutes'], 'duration' => ManageOvertime::duration($c['overtime_minutes']), 'day' => $c['day'], 'day_label' => $c['day_label'],
            'shifts' => $c['shifts'], 'amount' => $amount,
        ]]);
    }

    /** Claim my overtime. */
    public function storeMine(OvertimeClaimRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->claimRow($this->overtime->claim($this->me($request), $request->validated(), $request->user())->load('workflow.steps'), false)], 201);
    }

    /** Withdraw my claim waiting for approval. */
    public function withdraw(Request $request, Overtime $overtime): JsonResponse
    {
        abort_unless($overtime->employee_id === $this->me($request)->id, 404);

        return response()->json(['data' => $this->claimRow($this->overtime->withdraw($overtime, $request->user())->load('workflow.steps'), false)]);
    }

    private function claimRow(Overtime $o, bool $withEmployee): array
    {
        $e = $o->employee;

        return [
            'id' => $o->id, 'employee' => $withEmployee && $e ? ['id' => $e->id, 'name' => $e->full_name, 'employee_no' => $e->employee_no, 'department' => $e->department?->name] : null,
            'date' => $o->date_of_task_done->toDateString(), 'from' => $o->from_datetime->format('Y-m-d H:i'), 'to' => $o->to_datetime->format('Y-m-d H:i'),
            'overtime_minutes' => (int) $o->overtime_minutes, 'duration' => ManageOvertime::duration((int) $o->overtime_minutes), 'rate_unit' => $o->rate_unit,
            'rate_amount' => $o->rate_amount, 'amount' => (float) $o->amount, 'task_title' => $o->task_title, 'description' => $o->description, 'planned' => $o->planned,
            'status' => $o->status, 'status_label' => Overtime::STATUS_LABELS[$o->status] ?? $o->status, 'source' => $o->source,
            'waiting_for' => $o->status === 'pending' ? $o->workflow?->steps?->firstWhere('status', 'pending')?->name : null, 'decision_comment' => $o->decision_comment,
            'can_withdraw' => $o->status === 'pending',
        ];
    }

    private function rate(OvertimeRate $r): array
    {
        return [
            'id' => $r->id, 'department' => $r->department ? ['id' => $r->department->id, 'name' => $r->department->name] : null,
            'section' => $r->section ? ['id' => $r->section->id, 'name' => $r->section->name] : null,
            'position' => $r->jobPosition ? ['id' => $r->jobPosition->id, 'title' => $r->jobPosition->title] : null,
            'unit' => $r->unit, 'amount' => (float) $r->amount, 'is_active' => (bool) $r->is_active,
        ];
    }

    private function rateData(Request $request, ?OvertimeRate $rate = null): array
    {
        $company = app(CompanyContext::class)->id();
        $d = $request->validate([
            /** Department, or a section (its department is found). */
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('company_id', $company)->whereNull('deleted_at')],
            /** Empty: every position. */
            'job_position_id' => ['nullable', 'integer', Rule::exists('job_positions', 'id')->where('company_id', $company)],
            /** hour (paid by the minutes) or shift (one shift per claim). */
            'unit' => ['required', 'in:hour,shift'],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'is_active' => ['boolean'],
        ]);
        $department = Department::findOrFail($d['department_id']);
        [$departmentId, $sectionId] = $department->parent_id ? [$department->parent_id, $department->id] : [$department->id, null];
        abort_if(OvertimeRate::where('department_id', $departmentId)->where('section_id', $sectionId)->where('job_position_id', $d['job_position_id'] ?? null)
            ->when($rate, fn ($q) => $q->whereKeyNot($rate->id))->exists(), 422, 'This department and position already has an overtime rate.');

        return ['department_id' => $departmentId, 'section_id' => $sectionId, 'job_position_id' => $d['job_position_id'] ?? null, 'unit' => $d['unit'], 'amount' => $d['amount'],
            'is_active' => $d['is_active'] ?? $rate?->is_active ?? true];
    }

    private function canBrowse(Request $request): bool
    {
        $access = app(AccessService::class);

        return $access->can($request->user(), 'overtime.view') || $access->can($request->user(), 'overtime.manage');
    }

    private function me(Request $request): Employee
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();
        abort_unless($employee, 404, 'No employee record is linked to your account.');

        return $employee;
    }
}
