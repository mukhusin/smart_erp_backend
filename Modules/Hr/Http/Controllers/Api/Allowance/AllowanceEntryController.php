<?php

namespace Modules\Hr\Http\Controllers\Api\Allowance;

use App\Http\Controllers\Controller;
use App\Models\DailyAllowance;
use App\Models\Employee;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Core\Services\AccessService;
use Modules\Hr\Actions\ManageAllowances;
use Modules\Hr\Support\EmployeeScope;

/**
 * Allowances earned: worked out from the roster and working hours, or claimed and approved.
 *
 * @tags Allowance: Allowances
 */
class AllowanceEntryController extends Controller
{
    public function __construct(private readonly ManageAllowances $allowances) {}

    /**
     * Allowances of a month for the employees the user can see: totals per employee and category, and the entries.
     *
     * @response array{data: list<array{employee: array{id: int, name: string, employee_no: string|null, department: string|null}, total: float, categories: list<array{category: string, items: float, amount: float}>}>, entries: list<array{id: int, employee_id: int, category: string, unit: string, date: string, items: float, amount_per_item: float, amount: float, status: string, status_label: string, source: string, description: string|null, waiting_for: string|null, decision_comment: string|null}>, meta: array{month: string, total: float, can_manage: bool, claim_categories: list<array{id: int, name: string, unit: string}>}}
     */
    #[QueryParameter('month', type: 'string', description: 'e.g. 2026-11; default this month')]
    #[QueryParameter('employee_id', type: 'integer')]
    #[QueryParameter('department_id', type: 'integer')]
    #[QueryParameter('status', type: 'string', description: 'pending, approved, rejected, cancelled')]
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->canBrowse($request), 403);
        $f = $request->validate(['month' => ['nullable', 'date_format:Y-m'], 'employee_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:pending,approved,rejected,cancelled']]);
        $month = $f['month'] ?? now()->format('Y-m');
        $from = Carbon::createFromFormat('Y-m-d', "$month-01")->startOfMonth();
        $entries = DailyAllowance::with(['employee.department', 'workflow.steps'])
            ->whereHas('employee', fn ($q) => app(EmployeeScope::class)->apply($q, $request->user())
                ->when($f['department_id'] ?? null, fn ($q, $d) => $q->where(fn ($q) => $q->where('department_id', $d)->orWhere('section_id', $d))))
            ->when($f['employee_id'] ?? null, fn ($q, $e) => $q->where('employee_id', $e))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->whereDate('start_date', '>=', $from)->whereDate('start_date', '<=', $from->copy()->endOfMonth())
            ->orderBy('start_date')->get();
        $approved = $entries->where('status', 'approved');
        $summary = $approved->groupBy('employee_id')->map(function ($rows) {
            $e = $rows->first()->employee;

            return [
                'employee' => ['id' => $e->id, 'name' => $e->full_name, 'employee_no' => $e->employee_no, 'department' => $e->department?->name],
                'total' => round((float) $rows->sum('amount'), 2),
                'categories' => $rows->groupBy('category')->map(fn ($c, $name) => ['category' => $name, 'items' => (float) $c->sum('items'), 'amount' => round((float) $c->sum('amount'), 2)])->sortBy('category')->values(),
            ];
        })->sortBy('employee.name')->values();

        return response()->json([
            'data' => $summary,
            'entries' => $entries->map(fn ($e) => $this->entry($e))->values(),
            'meta' => [
                'month' => $month, 'total' => round((float) $approved->sum('amount'), 2), 'can_manage' => app(AccessService::class)->can($request->user(), 'allowance.daily.manage'),
                'claim_categories' => \App\Models\AllowanceCategory::where('is_active', true)->orderBy('category')->get()->reject->auto()
                    ->map(fn ($c) => ['id' => $c->id, 'name' => $c->category, 'unit' => $c->duration])->values(),
            ],
        ]);
    }

    /**
     * Work allowances out again from the roster and working hours for a period (e.g. after changing a roster or a rate).
     *
     * @response array{data: array{added: int, removed: int}}
     */
    public function generate(Request $request): JsonResponse
    {
        $d = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        abort_if(Carbon::parse($d['from'])->diffInDays(Carbon::parse($d['to'])) > 62, 422, 'Work out at most 62 days at a time.');
        $ids = app(EmployeeScope::class)->apply(Employee::query(), $request->user())->pluck('id');

        return response()->json(['data' => $this->allowances->generate($d['from'], $d['to'], $ids)]);
    }

    /**
     * Claim an allowance for an employee with a count (e.g. theatre cases); it goes through the approval workflow.
     *
     * @response array{data: array{id: int, employee_id: int, category: string, unit: string, date: string, items: float, amount_per_item: float, amount: float, status: string, status_label: string, source: string, description: string|null, waiting_for: string|null, decision_comment: string|null}}
     */
    public function claim(Request $request, Employee $employee): JsonResponse
    {
        abort_unless(app(EmployeeScope::class)->allows($request->user(), $employee), 404);
        $d = $request->validate([
            'allowance_category_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            /** How many (e.g. 3 cases). */
            'items' => ['required', 'numeric', 'min:0.5', 'max:1000'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $this->entry($this->allowances->claim($employee, $d, $request->user())->load('workflow.steps'))], 201);
    }

    /**
     * My allowances for a month (default this month).
     *
     * @response array{data: list<array{id: int, employee_id: int, category: string, unit: string, date: string, items: float, amount_per_item: float, amount: float, status: string, status_label: string, source: string, description: string|null, waiting_for: string|null, decision_comment: string|null}>, meta: array{month: string, total: float, categories: list<array{category: string, items: float, amount: float}>}}
     */
    #[QueryParameter('month', type: 'string')]
    public function mine(Request $request): JsonResponse
    {
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');
        $employee = Employee::where('user_id', $request->user()->id)->first();
        abort_unless($employee, 404, 'No employee record is linked to your account.');
        $from = Carbon::createFromFormat('Y-m-d', "$month-01")->startOfMonth();
        $rows = DailyAllowance::with('workflow.steps')->where('employee_id', $employee->id)->whereDate('start_date', '>=', $from)->whereDate('start_date', '<=', $from->copy()->endOfMonth())->orderBy('start_date')->get();
        $approved = $rows->where('status', 'approved');

        return response()->json([
            'data' => $rows->map(fn ($e) => $this->entry($e))->values(),
            'meta' => ['month' => $month, 'total' => round((float) $approved->sum('amount'), 2),
                'categories' => $approved->groupBy('category')->map(fn ($c, $name) => ['category' => $name, 'items' => (float) $c->sum('items'), 'amount' => round((float) $c->sum('amount'), 2)])->sortBy('category')->values()],
        ]);
    }

    private function entry(DailyAllowance $e): array
    {
        return [
            'id' => $e->id, 'employee_id' => $e->employee_id, 'category' => $e->category, 'unit' => $e->duration, 'date' => $e->start_date->toDateString(),
            'items' => (float) $e->items, 'amount_per_item' => (float) $e->amount_per_item, 'amount' => (float) $e->amount, 'status' => $e->status,
            'status_label' => DailyAllowance::STATUS_LABELS[$e->status] ?? $e->status, 'source' => $e->source, 'description' => $e->description,
            'waiting_for' => $e->status === 'pending' ? $e->workflow?->steps?->firstWhere('status', 'pending')?->name : null, 'decision_comment' => $e->decision_comment,
        ];
    }

    private function canBrowse(Request $request): bool
    {
        $access = app(AccessService::class);

        return collect(['allowance.view', 'allowance.department.view', 'allowance.daily.manage'])->contains(fn ($p) => $access->can($request->user(), $p));
    }
}
