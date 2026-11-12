<?php

namespace Modules\Hr\Http\Controllers\Api\Leave;

use App\Http\Controllers\Controller;
use App\Models\EmpLeave;
use App\Models\EmpLeaveFile;
use App\Models\Employee;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Core\Services\AccessService;
use Modules\Hr\Actions\ManageLeave;
use Modules\Hr\Http\Requests\LeaveRequestRequest;
use Modules\Hr\Http\Resources\LeaveRequestResource;
use Modules\Hr\Support\EmployeeFiles;
use Modules\Hr\Support\EmployeeScope;
use Modules\Hr\Support\LeaveBalances;
use Modules\Hr\Support\LeaveDays;
use Modules\Workflow\Services\WorkflowEngine;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Leave requests for HR and heads of department (the employees they can see), and approvers.
 *
 * @tags Leave: Requests
 */
class LeaveRequestController extends Controller
{
    public function __construct(private readonly ManageLeave $leave) {}

    /**
     * Leave requests, newest first.
     *
     * @response array{data: list<LeaveRequestResource>}
     */
    #[QueryParameter('status', type: 'string', description: 'pending, approved, on_leave, reported, rejected, cancelled')]
    #[QueryParameter('leave_type_id', type: 'integer')]
    #[QueryParameter('department_id', type: 'integer')]
    #[QueryParameter('employee_id', type: 'integer')]
    #[QueryParameter('month', type: 'string', description: 'Leave on any day of this month, e.g. 2026-12')]
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->canBrowse($request), 403);
        $f = $request->validate([
            'status' => ['nullable', 'in:pending,approved,on_leave,reported,rejected,cancelled'],
            'leave_type_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer'], 'employee_id' => ['nullable', 'integer'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $rows = EmpLeave::with(['leaveType', 'files', 'workflow.steps', 'employee.department'])
            ->whereHas('employee', fn ($q) => app(EmployeeScope::class)->apply($q, $request->user())
                ->when($f['department_id'] ?? null, fn ($q, $d) => $q->where(fn ($q) => $q->where('department_id', $d)->orWhere('section_id', $d))))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['leave_type_id'] ?? null, fn ($q, $t) => $q->where('leave_type_id', $t))
            ->when($f['employee_id'] ?? null, fn ($q, $e) => $q->where('employee_id', $e))
            ->when($f['month'] ?? null, function ($q, $m) {
                $start = Carbon::createFromFormat('Y-m-d', "$m-01")->startOfMonth();
                $q->whereDate('start_date', '<=', $start->copy()->endOfMonth())->whereDate('end_date', '>=', $start);
            })
            ->latest('start_date')->latest('id')->limit(300)->get();

        return response()->json(['data' => LeaveRequestResource::collection($rows)->resolve($request)]);
    }

    /**
     * A leave request as approvers see it: the request, the balance, how the days are counted, and changes.
     *
     * @response array{data: array{leave: LeaveRequestResource, balance: array{entitled: float, carried_over: float, adjustments: float, taken: float, pending: float, available: float, period_label: string}|null, breakdown: list<array{date: string, counted: bool, label: string}>, can_change: bool, can_record_return: bool, changes: list<array{id: int, kind: string, by: string|null, step: string|null, before: array{start_date: string, end_date: string, days: float}, after: array{start_date: string, end_date: string, days: float, report_date?: string}, comment: string|null, at: string}>}}
     */
    public function review(Request $request, EmpLeave $leave): JsonResponse
    {
        $this->authorizeView($request, $leave);
        $leave->load(['leaveType', 'files', 'workflow.steps', 'employee.department', 'changes.user.employee']);
        $type = $leave->leaveType;
        $balance = $type->yearly() ? app(LeaveBalances::class)->for($leave->employee, $type, (int) $leave->leave_year) : null;

        return response()->json(['data' => [
            'leave' => (new LeaveRequestResource($leave))->resolve($request),
            'balance' => $balance ? collect($balance)->only(['entitled', 'carried_over', 'adjustments', 'taken', 'pending', 'available', 'period_label'])->all() : null,
            'breakdown' => app(LeaveDays::class)->breakdown($leave->employee, $type, $leave->start_date, $leave->end_date)->values(),
            'can_change' => $this->leave->canChange($leave, $request->user()),
            'can_record_return' => in_array($leave->status, [EmpLeave::ON_LEAVE, EmpLeave::REPORTED], true) && app(AccessService::class)->can($request->user(), 'leave.approve-hr'),
            'changes' => $leave->changes->map(fn ($c) => [
                'id' => $c->id, 'kind' => $c->kind, 'by' => $c->user?->display_name, 'step' => $c->step_name, 'before' => $c->before, 'after' => $c->after,
                'comment' => $c->comment, 'at' => $c->created_at->toIso8601String(),
            ])->values(),
        ]]);
    }

    /** Change the first day and the number of days (HR, or the approver of the current step). */
    public function dates(Request $request, EmpLeave $leave): LeaveRequestResource
    {
        $data = $request->validate(['start_date' => ['required', 'date'], 'days' => ['required', 'integer', 'min:1', 'max:366'], 'comment' => ['nullable', 'string', 'max:500']]);

        return new LeaveRequestResource($this->leave->changeDates($leave, $data['start_date'], (int) $data['days'], $request->user(), $data['comment'] ?? null)
            ->load(['leaveType', 'files', 'workflow.steps', 'employee.department']));
    }

    /** Record the day the employee came back to work (HR). */
    public function recordReturn(Request $request, EmpLeave $leave): LeaveRequestResource
    {
        abort_unless(app(AccessService::class)->can($request->user(), 'leave.approve-hr'), 403);
        $data = $request->validate(['report_date' => ['required', 'date'], 'comment' => ['nullable', 'string', 'max:500']]);

        return new LeaveRequestResource($this->leave->recordReturn($leave, $data['report_date'], $request->user(), $data['comment'] ?? null)
            ->load(['leaveType', 'files', 'workflow.steps', 'employee.department']));
    }

    /** Request leave for an employee (HR). Notice is not required; the approval workflow still applies. */
    public function storeFor(LeaveRequestRequest $request, Employee $employee): LeaveRequestResource
    {
        abort_unless(app(AccessService::class)->can($request->user(), 'leave.approve-hr') && app(EmployeeScope::class)->allows($request->user(), $employee), 403);
        $leave = $this->leave->request($employee, $request->safe()->except('files'), $request->user(), 'hr', $request->file('files', []));

        return new LeaveRequestResource($leave->load(['leaveType', 'files', 'workflow.steps', 'employee.department']));
    }

    /** Download an attachment of a leave request. */
    public function file(Request $request, EmpLeave $leave, EmpLeaveFile $file): StreamedResponse
    {
        $this->authorizeView($request, $leave);
        abort_unless($file->emp_leave_id === $leave->id, 404);

        return EmployeeFiles::response($file->file_path, $file->file_name);
    }

    private function canBrowse(Request $request): bool
    {
        $access = app(AccessService::class);

        return collect(['leave.view', 'leave.department.view', 'leave.approve-hr', 'leave.approve-hod'])->contains(fn ($p) => $access->can($request->user(), $p));
    }

    private function authorizeView(Request $request, EmpLeave $leave): void
    {
        $user = $request->user();
        $allowed = $this->leave->canChange($leave, $user)
            || $leave->employee?->user_id === $user->id
            || ($this->canBrowse($request) && $leave->employee && app(EmployeeScope::class)->allows($user, $leave->employee))
            || ($leave->workflow && ($leave->workflow->steps()->where('acted_by', $user->id)->exists() || app(WorkflowEngine::class)->canAct($user, $leave->workflow)));
        abort_unless($allowed, 404);
    }
}
