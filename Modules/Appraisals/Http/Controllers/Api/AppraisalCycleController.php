<?php

namespace Modules\Appraisals\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Appraisals\Actions\ManageCycles;
use Modules\Appraisals\Models\Appraisal;
use Modules\Appraisals\Models\AppraisalCycle;
use Modules\Appraisals\Support\AppraisalAccess;

/**
 * @tags Appraisals
 */
class AppraisalCycleController extends Controller
{
    public function __construct(private readonly ManageCycles $cycles) {}

    /**
     * Appraisal periods (e.g. a year with a mid-year review), newest first.
     *
     * @response array{data: list<array{id: int, name: string, start_date: string, end_date: string, mid_year_date: string|null, self_due: string|null, review_due: string|null, status: string, status_label: string, total: int, done: int, average: float|null}>}
     */
    public function index(): JsonResponse
    {
        $cycles = AppraisalCycle::withCount(['appraisals as total' => fn ($q) => $q->where('status', '!=', 'cancelled'), 'appraisals as done' => fn ($q) => $q->where('status', 'done')])
            ->withAvg(['appraisals as average' => fn ($q) => $q->whereIn('status', ['acknowledge', 'done'])], 'final_score')
            ->latest('start_date')->get();

        return response()->json(['data' => $cycles->map(fn ($c) => $this->row($c))->values()]);
    }

    /** @response array{data: array{id: int, name: string, start_date: string, end_date: string, mid_year_date: string|null, self_due: string|null, review_due: string|null, status: string, status_label: string, total: int, done: int, average: float|null}} */
    public function store(Request $request): JsonResponse
    {
        $cycle = $this->cycles->create($this->validated($request), $request->user());

        return response()->json(['data' => $this->row($cycle)], 201);
    }

    /** @response array{data: array{id: int, name: string, start_date: string, end_date: string, mid_year_date: string|null, self_due: string|null, review_due: string|null, status: string, status_label: string, total: int, done: int, average: float|null}} */
    public function update(Request $request, AppraisalCycle $cycle): JsonResponse
    {
        return response()->json(['data' => $this->row($this->cycles->update($cycle, $this->validated($request)))]);
    }

    /**
     * Where a period stands: appraisals by status and per department, with average scores and ratings.
     *
     * @response array{data: array{id: int, name: string, start_date: string, end_date: string, mid_year_date: string|null, self_due: string|null, review_due: string|null, status: string, status_label: string, total: int, done: int, average: float|null}, statuses: list<array{status: string, label: string, count: int}>, departments: list<array{id: int|null, name: string, total: int, done: int, average: float|null}>, ratings: list<array{label: string, count: int}>}
     */
    public function show(Request $request, AppraisalCycle $cycle): JsonResponse
    {
        abort_unless(app(AppraisalAccess::class)->seesAll($request->user()), 403);
        $rows = $cycle->appraisals()->where('status', '!=', 'cancelled')->with('department')->get();
        $cycle->total = $rows->count();
        $cycle->done = $rows->where('status', 'done')->count();
        $cycle->average = $rows->whereNotNull('final_score')->avg('final_score');

        return response()->json([
            'data' => $this->row($cycle),
            'statuses' => collect(Appraisal::STATUS_LABELS)->except('cancelled')->map(fn ($label, $s) => ['status' => $s, 'label' => $label, 'count' => $rows->where('status', $s)->count()])->values(),
            'departments' => $rows->groupBy('department_id')->map(fn ($g) => ['id' => $g->first()->department_id, 'name' => $g->first()->department?->name ?? 'No department', 'total' => $g->count(),
                'done' => $g->where('status', 'done')->count(), 'average' => $g->whereNotNull('final_score')->isEmpty() ? null : round((float) $g->whereNotNull('final_score')->avg('final_score'), 1)])
                ->sortBy('name')->values(),
            'ratings' => $rows->whereNotNull('rating')->groupBy('rating')->map(fn ($g, $label) => ['label' => (string) $label, 'count' => $g->count()])->values(),
        ]);
    }

    /**
     * Open the period: every active staff member (or those of the chosen departments) gets an appraisal. Run again to add staff who joined later.
     *
     * @response array{data: array{created: int}}
     */
    public function open(Request $request, AppraisalCycle $cycle): JsonResponse
    {
        $data = $request->validate(['department_ids' => ['nullable', 'array'], 'department_ids.*' => ['integer']]);
        $ids = Department::whereIn('id', $data['department_ids'] ?? [])->pluck('id')->all();

        return response()->json(['data' => ['created' => $this->cycles->open($cycle, $ids)]]);
    }

    /** @response array{data: array{id: int, name: string, start_date: string, end_date: string, mid_year_date: string|null, self_due: string|null, review_due: string|null, status: string, status_label: string, total: int, done: int, average: float|null}} */
    public function close(AppraisalCycle $cycle): JsonResponse
    {
        return response()->json(['data' => $this->row($this->cycles->close($cycle))]);
    }

    /** @response array{message: string} */
    public function destroy(AppraisalCycle $cycle): JsonResponse
    {
        $this->cycles->delete($cycle);

        return response()->json(['message' => 'Period deleted.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'mid_year_date' => ['nullable', 'date', 'after:start_date', 'before:end_date'],
            'self_due' => ['nullable', 'date', 'after_or_equal:start_date'],
            'review_due' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
    }

    private function row(AppraisalCycle $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'start_date' => $c->start_date->toDateString(), 'end_date' => $c->end_date->toDateString(),
            'mid_year_date' => $c->mid_year_date?->toDateString(), 'self_due' => $c->self_due?->toDateString(), 'review_due' => $c->review_due?->toDateString(),
            'status' => $c->status, 'status_label' => AppraisalCycle::STATUS_LABELS[$c->status] ?? $c->status,
            'total' => (int) ($c->total ?? 0), 'done' => (int) ($c->done ?? 0), 'average' => $c->average !== null ? round((float) $c->average, 1) : null,
        ];
    }
}
