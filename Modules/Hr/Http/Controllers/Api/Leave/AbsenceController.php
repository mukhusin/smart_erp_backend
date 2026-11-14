<?php

namespace Modules\Hr\Http\Controllers\Api\Leave;

use App\Http\Controllers\Controller;
use App\Models\AbsenceRequest;
use App\Models\Employee;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Core\Services\AccessService;
use Modules\Hr\Actions\ManageAbsences;
use Modules\Hr\Support\EmployeeScope;

/**
 * @tags Leave: Official absences
 */
class AbsenceController extends Controller
{
    public function __construct(private readonly ManageAbsences $absences, private readonly EmployeeScope $scope, private readonly AccessService $access) {}

    /**
     * Official absences overlapping a month (default this month), of the employees I may see.
     *
     * `status`: pending, approved (not ended), ended, rejected, cancelled; empty = all.
     *
     * @response array{data: list<array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, from_date: string, to_date: string, from_time: string|null, to_time: string|null, number_of_days: int, address: string|null, description: string, has_per_diem: bool, status: string, status_label: string, waiting_for: string|null, decision_comment: string|null, requested_by: string|null, can_cancel: bool}>}
     */
    #[QueryParameter('month', type: 'string', example: '2026-11')]
    #[QueryParameter('status', type: 'string')]
    #[QueryParameter('employee_id', type: 'integer')]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(collect(['leave.view', 'leave.department.view', 'leave.absence.manage'])->contains(fn ($p) => $this->access->can($user, $p)), 403);
        $d = $request->validate(['month' => ['nullable', 'date_format:Y-m'], 'status' => ['nullable', 'in:pending,approved,ended,rejected,cancelled'], 'employee_id' => ['nullable', 'integer']]);
        $from = Carbon::createFromFormat('Y-m-d', ($d['month'] ?? now()->format('Y-m')).'-01')->startOfMonth();
        $rows = AbsenceRequest::with(['employee.department', 'requestedBy', 'workflow.steps'])
            ->whereHas('employee', fn ($q) => $this->scope->apply($q, $user))
            ->when($d['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->whereDate('from_date', '<=', $from->copy()->endOfMonth())->whereDate('to_date', '>=', $from)
            ->orderByDesc('from_date')->get()
            ->filter(fn ($a) => empty($d['status']) || $a->shownStatus() === $d['status']);

        return response()->json(['data' => $rows->map(fn ($a) => $this->row($a, $request))->values()]);
    }

    /**
     * Record an official absence for one or more employees (one record each, each through the approval workflow).
     *
     * @response array{data: list<array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, from_date: string, to_date: string, from_time: string|null, to_time: string|null, number_of_days: int, address: string|null, description: string, has_per_diem: bool, status: string, status_label: string, waiting_for: string|null, decision_comment: string|null, requested_by: string|null, can_cancel: bool}>}
     */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1', 'max:100'], 'employee_ids.*' => ['integer', 'distinct'],
            'from_date' => ['required', 'date'], 'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'from_time' => ['nullable', 'date_format:H:i'], 'to_time' => ['nullable', 'date_format:H:i'],
            'address' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:2000'], 'has_per_diem' => ['sometimes', 'boolean'],
        ], ['address.required' => 'Say where the employee will be.', 'description.required' => 'Say what the absence is for.']);
        abort_if(Carbon::parse($d['from_date'])->diffInDays(Carbon::parse($d['to_date'])) > 365, 422, 'An absence can be at most one year.');
        $employees = $this->scope->apply(Employee::query(), $request->user())->whereIn('id', $d['employee_ids'])->get();
        abort_unless($employees->count() === count($d['employee_ids']), 404);

        $rows = $this->absences->create($employees, $d, $request->user());

        return response()->json(['data' => $rows->map(fn ($a) => $this->row($a->load(['employee.department', 'requestedBy', 'workflow.steps']), $request))->values()], 201);
    }

    /**
     * Withdraw a pending absence I recorded, or cancel an approved absence that has not ended (its days count again).
     *
     * @response array{data: array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}|null, from_date: string, to_date: string, from_time: string|null, to_time: string|null, number_of_days: int, address: string|null, description: string, has_per_diem: bool, status: string, status_label: string, waiting_for: string|null, decision_comment: string|null, requested_by: string|null, can_cancel: bool}}
     */
    public function cancel(Request $request, AbsenceRequest $absence): JsonResponse
    {
        abort_unless($absence->employee && $this->scope->allows($request->user(), $absence->employee), 404);

        return response()->json(['data' => $this->row($this->absences->cancel($absence, $request->user())->load(['employee.department', 'requestedBy', 'workflow.steps']), $request)]);
    }

    private function row(AbsenceRequest $a, Request $request): array
    {
        $status = $a->shownStatus();
        $instance = $a->workflow;
        $step = $instance?->status === 'pending' ? $instance->steps->firstWhere('status', 'pending') : null;
        $mayManage = $this->access->can($request->user(), 'leave.absence.manage');

        return [
            'id' => $a->id,
            'employee' => $a->employee ? ['id' => $a->employee->id, 'name' => $a->employee->full_name, 'employee_no' => $a->employee->employee_no, 'department' => $a->employee->department?->name] : null,
            'from_date' => $a->from_date->toDateString(), 'to_date' => $a->to_date->toDateString(),
            'from_time' => $a->from_time ? substr($a->from_time, 0, 5) : null, 'to_time' => $a->to_time ? substr($a->to_time, 0, 5) : null,
            'number_of_days' => (int) $a->number_of_days, 'address' => $a->address, 'description' => $a->description, 'has_per_diem' => (bool) $a->has_per_diem,
            'status' => $status, 'status_label' => AbsenceRequest::STATUS_LABELS[$status] ?? $status,
            'waiting_for' => $step?->name, 'decision_comment' => $a->decision_comment, 'requested_by' => $a->requestedBy?->display_name,
            'can_cancel' => $mayManage && ($status === 'approved' || ($status === 'pending' && $instance?->initiated_by === $request->user()->id) || ($status === 'pending' && ! $instance)),
        ];
    }
}
