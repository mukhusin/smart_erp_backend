<?php

namespace Modules\Hr\Http\Controllers\Api\Payroll;

use App\Http\Controllers\Controller;
use App\Models\AllowanceCategory;
use App\Models\EmpAllowance;
use App\Models\Employee;
use App\Models\OtherDeduction;
use App\Models\SalaryAdjustment;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Hr\Actions\ManagePayItems;
use Modules\Hr\Http\Requests\AssignedAllowanceRequest;
use Modules\Hr\Http\Requests\OtherDeductionRequest;
use Modules\Hr\Http\Requests\SalaryAdjustmentRequest;
use Modules\Hr\Support\EmployeeScope;

/**
 * Pay items set per employee: assigned monthly allowances, other deductions and salary adjustments (each through its workflow).
 *
 * @tags Payroll: Pay items
 */
class PayItemsController extends Controller
{
    public function __construct(private readonly ManagePayItems $items) {}

    /**
     * Assigned monthly allowances, with the categories that can be assigned.
     *
     * @response array{data: list<array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}, category: string, category_id: int|null, amount: float, start_date: string, end_date: string|null, description: string|null, status: string, running: bool, waiting_for: string|null, decision_comment: string|null}>, categories: list<array{id: int, name: string, deduct_leave: bool, other_absence: bool, taxable: bool}>}
     */
    #[QueryParameter('status', type: 'string', description: 'running (default), pending, ended, all')]
    #[QueryParameter('employee_id', type: 'integer')]
    public function allowances(Request $request): JsonResponse
    {
        $f = $this->filters($request);
        $rows = EmpAllowance::with(['employee.department', 'workflow.steps'])->whereHas('employee', fn ($q) => app(EmployeeScope::class)->apply($q, $request->user()))
            ->when($f['employee_id'] ?? null, fn ($q, $e) => $q->where('employee_id', $e))->latest('start_date')->latest('id')->limit(500)->get()
            ->filter(fn ($a) => $this->matches($f['status'] ?? 'running', $a->status, $a->status === 'approved' && (! $a->end_date || ! $a->end_date->isPast())));

        return response()->json([
            'data' => $rows->map(fn ($a) => $this->allowanceRow($a))->values(),
            'categories' => AllowanceCategory::where('is_active', true)->where('generation', 'assigned')->orderBy('category')->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->category, 'deduct_leave' => (bool) $c->deduct_leave, 'other_absence' => (bool) $c->other_absence, 'taxable' => (bool) $c->taxable])->values(),
        ]);
    }

    /**
     * Assign a monthly allowance to an employee.
     *
     * @response array{data: array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}, category: string, category_id: int|null, amount: float, start_date: string, end_date: string|null, description: string|null, status: string, running: bool, waiting_for: string|null, decision_comment: string|null}}
     */
    public function storeAllowance(AssignedAllowanceRequest $request, Employee $employee): JsonResponse
    {
        $this->inScope($request, $employee);

        return response()->json(['data' => $this->allowanceRow($this->items->assignAllowance($employee, $request->validated(), $request->user())->load(['employee.department', 'workflow.steps']))], 201);
    }

    /**
     * Stop an assigned allowance from a date (the last day it is paid).
     *
     * @response array{data: array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}, category: string, category_id: int|null, amount: float, start_date: string, end_date: string|null, description: string|null, status: string, running: bool, waiting_for: string|null, decision_comment: string|null}}
     */
    public function stopAllowance(Request $request, EmpAllowance $allowance): JsonResponse
    {
        $this->inScope($request, $allowance->employee);
        $this->items->stop($allowance, $request->validate(['date' => ['required', 'date']])['date']);

        return response()->json(['data' => $this->allowanceRow($allowance->refresh()->load(['employee.department', 'workflow.steps']))]);
    }

    /** Remove an assigned allowance that is not approved (a pending one is withdrawn). */
    public function destroyAllowance(Request $request, EmpAllowance $allowance): Response
    {
        $this->inScope($request, $allowance->employee);
        $this->items->delete($allowance, $request->user());

        return response()->noContent();
    }

    /**
     * Other deductions (a fixed amount every month).
     *
     * @response array{data: list<array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}, name: string, amount: float, start_month: string, end_month: string|null, months: int|null, description: string|null, status: string, running: bool, waiting_for: string|null, decision_comment: string|null}>, meta: array{monthly: float}}
     */
    #[QueryParameter('status', type: 'string', description: 'running (default), pending, ended, all')]
    #[QueryParameter('employee_id', type: 'integer')]
    public function deductions(Request $request): JsonResponse
    {
        $f = $this->filters($request);
        $month = now()->startOfMonth();
        $rows = OtherDeduction::with(['employee.department', 'workflow.steps'])->whereHas('employee', fn ($q) => app(EmployeeScope::class)->apply($q, $request->user()))
            ->when($f['employee_id'] ?? null, fn ($q, $e) => $q->where('employee_id', $e))->latest('start_month')->latest('id')->limit(500)->get()
            ->filter(fn ($d) => $this->matches($f['status'] ?? 'running', $d->status, $d->status === 'approved' && (! $d->endMonth() || $d->endMonth()->gte($month))));

        return response()->json([
            'data' => $rows->map(fn ($d) => $this->deductionRow($d))->values(),
            'meta' => ['monthly' => round((float) $rows->filter(fn ($d) => $d->appliesTo($month->year, $month->month))->sum('amount'), 2)],
        ]);
    }

    /**
     * Add a monthly deduction for an employee.
     *
     * @response array{data: array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}, name: string, amount: float, start_month: string, end_month: string|null, months: int|null, description: string|null, status: string, running: bool, waiting_for: string|null, decision_comment: string|null}}
     */
    public function storeDeduction(OtherDeductionRequest $request, Employee $employee): JsonResponse
    {
        $this->inScope($request, $employee);

        return response()->json(['data' => $this->deductionRow($this->items->addDeduction($employee, $request->validated(), $request->user())->load(['employee.department', 'workflow.steps']))], 201);
    }

    /**
     * Stop a deduction: the given month (Y-m) is the last one deducted.
     *
     * @response array{data: array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}, name: string, amount: float, start_month: string, end_month: string|null, months: int|null, description: string|null, status: string, running: bool, waiting_for: string|null, decision_comment: string|null}}
     */
    public function stopDeduction(Request $request, OtherDeduction $deduction): JsonResponse
    {
        $this->inScope($request, $deduction->employee);
        $this->items->stop($deduction, $request->validate(['month' => ['required', 'date_format:Y-m']])['month'].'-01');

        return response()->json(['data' => $this->deductionRow($deduction->refresh()->load(['employee.department', 'workflow.steps']))]);
    }

    /** Remove a deduction that is not approved (a pending one is withdrawn). */
    public function destroyDeduction(Request $request, OtherDeduction $deduction): Response
    {
        $this->inScope($request, $deduction->employee);
        $this->items->delete($deduction, $request->user());

        return response()->noContent();
    }

    /**
     * Salary adjustments, newest first.
     *
     * @response array{data: list<array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}, type: string, current_salary: float, new_salary: float, amount: float, percentage: float|null, reason: string, reason_label: string, effective_date: string|null, applied_at: string|null, description: string|null, status: string, waiting_for: string|null, decision_comment: string|null}>, reasons: array<string, string>}
     */
    #[QueryParameter('status', type: 'string', description: 'pending, approved, rejected, all (default)')]
    #[QueryParameter('employee_id', type: 'integer')]
    public function adjustments(Request $request): JsonResponse
    {
        $f = $this->filters($request);
        $rows = SalaryAdjustment::with(['employee.department', 'workflow.steps'])->whereHas('employee', fn ($q) => app(EmployeeScope::class)->apply($q, $request->user()))
            ->when($f['employee_id'] ?? null, fn ($q, $e) => $q->where('employee_id', $e))
            ->when(! in_array($f['status'] ?? 'all', ['all', 'running', 'ended'], true), fn ($q) => $q->where('status', $f['status']))
            ->latest()->limit(500)->get();

        return response()->json(['data' => $rows->map(fn ($a) => $this->adjustmentRow($a))->values(), 'reasons' => SalaryAdjustment::REASONS]);
    }

    /**
     * Ask to change an employee's basic salary.
     *
     * @response array{data: array{id: int, employee: array{id: int, name: string, employee_no: string|null, department: string|null}, type: string, current_salary: float, new_salary: float, amount: float, percentage: float|null, reason: string, reason_label: string, effective_date: string|null, applied_at: string|null, description: string|null, status: string, waiting_for: string|null, decision_comment: string|null}}
     */
    public function storeAdjustment(SalaryAdjustmentRequest $request, Employee $employee): JsonResponse
    {
        $this->inScope($request, $employee);

        return response()->json(['data' => $this->adjustmentRow($this->items->adjustSalary($employee, $request->validated(), $request->user())->load(['employee.department', 'workflow.steps']))], 201);
    }

    /** Remove a salary adjustment that is not approved (a pending one is withdrawn). */
    public function destroyAdjustment(Request $request, SalaryAdjustment $adjustment): Response
    {
        $this->inScope($request, $adjustment->employee);
        $this->items->delete($adjustment, $request->user());

        return response()->noContent();
    }

    private function filters(Request $request): array
    {
        return $request->validate(['status' => ['nullable', 'in:running,pending,approved,rejected,ended,all'], 'employee_id' => ['nullable', 'integer']]);
    }

    private function matches(string $filter, string $status, bool $running): bool
    {
        return match ($filter) {
            'all' => true, 'running' => $running, 'ended' => $status === 'approved' && ! $running, default => $status === $filter,
        };
    }

    private function inScope(Request $request, ?Employee $employee): void
    {
        abort_unless($employee && app(EmployeeScope::class)->allows($request->user(), $employee), 404);
    }

    private function employee(?Employee $e): array
    {
        return ['id' => $e?->id, 'name' => $e?->full_name, 'employee_no' => $e?->employee_no, 'department' => $e?->department?->name];
    }

    private function waiting($item): ?string
    {
        return $item->status === 'pending' ? $item->workflow?->steps?->firstWhere('status', 'pending')?->name : null;
    }

    private function allowanceRow(EmpAllowance $a): array
    {
        return [
            'id' => $a->id, 'employee' => $this->employee($a->employee), 'category' => $a->category, 'category_id' => $a->allowance_category_id, 'amount' => (float) $a->amount,
            'start_date' => $a->start_date->toDateString(), 'end_date' => $a->end_date?->toDateString(), 'description' => $a->description, 'status' => $a->status,
            'running' => $a->status === 'approved' && (! $a->end_date || ! $a->end_date->isPast()), 'waiting_for' => $this->waiting($a), 'decision_comment' => $a->decision_comment,
        ];
    }

    private function deductionRow(OtherDeduction $d): array
    {
        $end = $d->endMonth();

        return [
            'id' => $d->id, 'employee' => $this->employee($d->employee), 'name' => $d->name, 'amount' => (float) $d->amount, 'start_month' => $d->start_month->format('Y-m'),
            'end_month' => $end?->format('Y-m'), 'months' => $d->months, 'description' => $d->description, 'status' => $d->status,
            'running' => $d->status === 'approved' && (! $end || $end->gte(now()->startOfMonth())), 'waiting_for' => $this->waiting($d), 'decision_comment' => $d->decision_comment,
        ];
    }

    private function adjustmentRow(SalaryAdjustment $a): array
    {
        return [
            'id' => $a->id, 'employee' => $this->employee($a->employee), 'type' => $a->adjustment_type, 'current_salary' => (float) $a->current_salary, 'new_salary' => (float) $a->new_salary,
            'amount' => (float) $a->amount, 'percentage' => $a->percentage !== null ? (float) $a->percentage : null, 'reason' => $a->reason,
            'reason_label' => SalaryAdjustment::REASONS[$a->reason] ?? $a->reason, 'effective_date' => $a->effective_date?->toDateString(),
            'applied_at' => $a->applied_at?->toIso8601String(), 'description' => $a->description, 'status' => $a->status, 'waiting_for' => $this->waiting($a), 'decision_comment' => $a->director_comment,
        ];
    }
}
