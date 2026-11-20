<?php

namespace Modules\Hr\Http\Controllers\Api\Allowance;

use App\Http\Controllers\Controller;
use App\Models\AllowanceBatch;
use App\Models\AllowanceBatchLine;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Services\AccessService;
use Modules\Hr\Actions\ManageAllowanceBatches;
use Modules\Hr\Support\EmployeeScope;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * Daily allowance batches: a head enters one day's counts (e.g. theatre cases) for their team and sends them for approval together.
 *
 * @tags Allowance: Daily batches
 */
class AllowanceBatchController extends Controller
{
    public function __construct(private readonly ManageAllowanceBatches $batches, private readonly EmployeeScope $scope) {}

    /**
     * Batches of the teams the user can see.
     *
     * @response array{data: list<array{id: int, category: string, department: string, date: string, status: string, status_label: string, employees: int, items: float, amount: float, created_by: string|null, waiting_for: string|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null, choices: list<array{category_id: int, category: string, department_id: int, department: string}>}}
     */
    #[QueryParameter('status', type: 'string', description: 'draft, pending, approved, rejected')]
    #[QueryParameter('month', type: 'string', description: 'e.g. 2026-11')]
    #[QueryParameter('search', type: 'string')]
    #[QueryParameter('sort', type: 'string', description: 'date, status, amount; - for descending')]
    #[QueryParameter('page', type: 'integer')]
    #[QueryParameter('per_page', type: 'integer')]
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', 'in:draft,pending,approved,rejected'], 'month' => ['nullable', 'date_format:Y-m'], 'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', 'max:20'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $user = $request->user();
        $departments = $this->batches->teams($user);
        $sortParam = $f['sort'] ?? '-date';
        $sort = in_array(ltrim($sortParam, '-'), ['date', 'status', 'amount'], true) ? ltrim($sortParam, '-') : 'date';
        $page = AllowanceBatch::with(['category', 'department', 'creator', 'workflow.steps'])->withCount('lines')->withSum('lines', 'items')->withSum('lines', 'amount')
            ->where(fn ($q) => $q->where('created_by', $user->id)->orWhereIn('department_id', $departments->pluck('id')))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['month'] ?? null, fn ($q, $m) => $q->where('date', '>=', "$m-01")->where('date', '<=', date('Y-m-t', strtotime("$m-01"))))
            ->when($f['search'] ?? null, fn ($q, $t) => $q->where(fn ($q) => $q->whereHas('department', fn ($q) => $q->where('name', 'like', "%{$t}%"))->orWhereHas('category', fn ($q) => $q->where('category', 'like', "%{$t}%"))))
            ->orderBy($sort === 'amount' ? 'lines_sum_amount' : $sort, str_starts_with($sortParam, '-') ? 'desc' : 'asc')->orderByDesc('id')
            ->paginate($f['per_page'] ?? 10);

        return response()->json([
            'data' => collect($page->items())->map(fn ($b) => $this->row($b))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
                'choices' => $this->batches->choices($departments)],
        ]);
    }

    /**
     * Start a draft batch for a team and day.
     *
     * @response array{data: array{id: int, category: string, department: string, date: string, status: string, status_label: string, employees: int, items: float, amount: float, created_by: string|null, waiting_for: string|null}}
     */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['allowance_category_id' => ['required', 'integer'], 'department_id' => ['required', 'integer'], 'date' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:500']]);
        $batch = $this->batches->create($d, $request->user());

        return response()->json(['data' => $this->row($batch->load('category', 'department', 'creator')->loadCount('lines')->loadSum('lines', 'items')->loadSum('lines', 'amount'))], 201);
    }

    /**
     * A batch with its employees. Open to its team's managers and the approvers.
     *
     * @response array{data: array{batch: array{id: int, category: string, department: string, date: string, status: string, status_label: string, employees: int, items: float, amount: float, created_by: string|null, waiting_for: string|null, notes: string|null, decision_comment: string|null, workflow_id: int|null, can_edit: bool}, lines: list<array{id: int, employee_id: int, name: string, employee_no: string|null, position: string|null, items: float, amount_per_item: float, amount: float}>}}
     */
    public function show(Request $request, AllowanceBatch $batch): JsonResponse
    {
        abort_unless($this->canSee($request, $batch), 404);
        $batch->load(['category', 'department', 'creator', 'workflow.steps', 'lines.employee.jobPosition'])->loadCount('lines')->loadSum('lines', 'items')->loadSum('lines', 'amount');

        return response()->json(['data' => [
            'batch' => $this->row($batch) + ['notes' => $batch->notes, 'decision_comment' => $batch->decision_comment, 'workflow_id' => $batch->workflow?->id, 'can_edit' => $batch->editable() && $this->canManage($request, $batch)],
            'lines' => $batch->lines->sortBy(fn ($l) => $l->employee?->full_name)->map(fn (AllowanceBatchLine $l) => [
                'id' => $l->id, 'employee_id' => $l->employee_id, 'name' => $l->employee?->full_name ?? '', 'employee_no' => $l->employee?->employee_no, 'position' => $l->employee?->jobPosition?->title,
                'items' => $l->items, 'amount_per_item' => $l->amount_per_item, 'amount' => $l->amount,
            ])->values(),
        ]]);
    }

    /**
     * Change the notes of a draft.
     *
     * @response array{data: array{id: int}}
     */
    public function update(Request $request, AllowanceBatch $batch): JsonResponse
    {
        abort_unless($this->canManage($request, $batch), 404);
        $d = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);
        abort_unless($batch->editable(), 422, 'Only a draft or a batch that was not approved can be changed.');
        $batch->update(['notes' => $d['notes'] ?? null]);

        return response()->json(['data' => ['id' => $batch->id]]);
    }

    /** Delete a draft. */
    public function destroy(Request $request, AllowanceBatch $batch): JsonResponse
    {
        abort_unless($this->canManage($request, $batch), 404);
        $this->batches->delete($batch);

        return response()->json(null, 204);
    }

    /**
     * The team for the batch, with the reason anyone cannot be added (on leave, no rate...).
     *
     * @response array{data: list<array{id: int, name: string, employee_no: string|null, position: string|null, rate: float|null, blocked: string|null}>}
     */
    #[QueryParameter('search', type: 'string')]
    public function employees(Request $request, AllowanceBatch $batch): JsonResponse
    {
        abort_unless($this->canManage($request, $batch), 404);
        $search = $request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? null;
        $batch->load('category');

        return response()->json(['data' => $this->batches->candidates($batch, $request->user(), $search)->map(fn ($c) => [
            'id' => $c['employee']->id, 'name' => $c['employee']->full_name, 'employee_no' => $c['employee']->employee_no, 'position' => $c['employee']->jobPosition?->title,
            'rate' => $c['rate'] ? (float) $c['rate']->amount : null, 'blocked' => $c['blocked'],
        ])->values()]);
    }

    /**
     * Add an employee with their count, or change it.
     *
     * @response array{data: array{id: int, employee_id: int, items: float, amount_per_item: float, amount: float}}
     */
    public function setLine(Request $request, AllowanceBatch $batch): JsonResponse
    {
        abort_unless($this->canManage($request, $batch), 404);
        $d = $request->validate(['employee_id' => ['required', 'integer'],
            /** How many (e.g. 3 surgeries). */
            'items' => ['required', 'numeric', 'min:0.5', 'max:1000']]);
        $line = $this->batches->setLine($batch->load('category'), (int) $d['employee_id'], (float) $d['items'], $request->user());

        return response()->json(['data' => ['id' => $line->id, 'employee_id' => $line->employee_id, 'items' => $line->items, 'amount_per_item' => $line->amount_per_item, 'amount' => $line->amount]]);
    }

    /** Remove an employee from a draft. */
    public function removeLine(Request $request, AllowanceBatch $batch, AllowanceBatchLine $line): JsonResponse
    {
        abort_unless($this->canManage($request, $batch), 404);
        $this->batches->removeLine($batch, $line);

        return response()->json(null, 204);
    }

    /**
     * Send the batch for approval.
     *
     * @response array{data: array{id: int, status: string}}
     */
    public function submit(Request $request, AllowanceBatch $batch): JsonResponse
    {
        abort_unless($this->canManage($request, $batch), 404);
        $batch = $this->batches->submit($batch, $request->user());

        return response()->json(['data' => ['id' => $batch->id, 'status' => $batch->status]]);
    }

    private function row(AllowanceBatch $b): array
    {
        return [
            'id' => $b->id, 'category' => $b->category?->category ?? '', 'department' => $b->department?->name ?? '', 'date' => $b->date->toDateString(),
            'status' => $b->status, 'status_label' => AllowanceBatch::STATUS_LABELS[$b->status] ?? $b->status, 'employees' => (int) ($b->lines_count ?? 0),
            'items' => round((float) ($b->lines_sum_items ?? 0), 2), 'amount' => round((float) ($b->lines_sum_amount ?? 0), 2), 'created_by' => $b->creator?->display_name,
            'waiting_for' => $b->status === 'pending' ? $b->workflow?->steps?->firstWhere('status', 'pending')?->name : null,
        ];
    }

    private function canManage(Request $request, AllowanceBatch $batch): bool
    {
        return app(AccessService::class)->can($request->user(), 'allowance.daily.manage') && $this->batches->teamQuery($request->user(), $batch->department_id)->exists();
    }

    private function canSee(Request $request, AllowanceBatch $batch): bool
    {
        $user = $request->user();
        $access = app(AccessService::class);
        if ($batch->created_by === $user->id || $this->canManage($request, $batch)) {
            return true;
        }
        if (collect(['allowance.view', 'allowance.department.view'])->contains(fn ($p) => $access->can($user, $p)) && $this->batches->teamQuery($user, $batch->department_id)->exists()) {
            return true;
        }
        $wf = $batch->workflow;

        return $wf && (app(WorkflowEngine::class)->canAct($user, $wf) || $wf->steps()->where('acted_by', $user->id)->exists());
    }
}
