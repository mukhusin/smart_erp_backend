<?php

namespace Modules\Appraisals\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Appraisals\Actions\ManageAppraisals;
use Modules\Appraisals\Models\Appraisal;
use Modules\Appraisals\Models\AppraisalScore;
use Modules\Appraisals\Support\AppraisalAccess;
use Modules\Appraisals\Support\AppraisalScoring;
use Modules\Core\Support\PdfDocument;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Appraisals
 */
class AppraisalController extends Controller
{
    public function __construct(private readonly AppraisalAccess $access, private readonly AppraisalScoring $scoring, private readonly ManageAppraisals $appraisals) {}

    /**
     * List appraisals. `view=mine` the user's own, `team` staff they head or must rate, `all` every appraisal (appraisals.view).
     *
     * @response array{data: list<array{id: int, employee: array{id: int, name: string, employee_no: string|null}|null, department: string|null, cycle: array{id: int, name: string}|null, status: string, status_label: string, final_score: float|null, rating: string|null, waiting_for: string|null, awaiting_me: bool, submitted_at: string|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('view', description: '`mine`, `team` or `all`.', type: 'string', default: 'mine')]
    #[QueryParameter('filter[search]', description: 'Employee name or number.', type: 'string')]
    #[QueryParameter('filter[status]', description: 'A status, or `awaiting_me`.', type: 'string')]
    #[QueryParameter('filter[appraisal_cycle_id]', type: 'integer')]
    #[QueryParameter('filter[department_id]', type: 'integer')]
    #[QueryParameter('sort', description: '`final_score`, `submitted_at`, `created_at`; prefix `-` for descending.', type: 'string', default: '-created_at')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Max 100.', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $view = in_array($request->query('view'), ['team', 'all'], true) ? $request->query('view') : 'mine';
        $awaiting = $view === 'mine' ? [] : $this->access->awaiting($user);
        $page = QueryBuilder::for($this->access->visible(Appraisal::query()->with(['employee', 'department', 'cycle', 'workflow.steps']), $user, $view))
            ->allowedFilters(
                AllowedFilter::callback('search', fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->withoutGlobalScopes()->where(fn ($e) => $e->where('fname', 'like', "%{$v}%")->orWhere('lname', 'like', "%{$v}%")->orWhere('employee_no', 'like', "%{$v}%")))),
                AllowedFilter::callback('status', fn ($q, $v) => $v === 'awaiting_me' ? $q->whereIn('id', $awaiting) : $q->where('status', $v)),
                AllowedFilter::exact('appraisal_cycle_id'),
                AllowedFilter::exact('department_id'),
            )
            ->allowedSorts('final_score', 'submitted_at', 'created_at')
            ->defaultSort('-created_at')
            ->paginate(min((int) $request->query('per_page', 10), 100))
            ->withQueryString();
        $mine = $view === 'mine';

        return response()->json([
            'data' => collect($page->items())->map(fn (Appraisal $a) => [
                'id' => $a->id,
                'employee' => $a->employee ? ['id' => $a->employee->id, 'name' => $a->employee->full_name, 'employee_no' => $a->employee->employee_no] : null,
                'department' => $a->department?->name,
                'cycle' => $a->cycle ? ['id' => $a->cycle->id, 'name' => $a->cycle->name] : null,
                'status' => $a->status, 'status_label' => Appraisal::STATUS_LABELS[$a->status] ?? $a->status,
                'final_score' => $mine && ! in_array($a->status, ['acknowledge', 'done'], true) ? null : $a->final_score,
                'rating' => $mine && ! in_array($a->status, ['acknowledge', 'done'], true) ? null : $a->rating,
                'waiting_for' => $a->status === 'review' ? $a->workflow?->steps->firstWhere('status', 'pending')?->name : null,
                'awaiting_me' => in_array($a->id, $awaiting, true),
                'submitted_at' => $a->submitted_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /**
     * An appraisal with the period's parts and competencies, the scores the user may see, the tasks of the period and what the user can do.
     *
     * @response array{data: array{id: int, status: string, status_label: string, employee: array{id: int, name: string, employee_no: string|null, position: string|null}|null, department: string|null, cycle: array{id: int, name: string, start_date: string, end_date: string, mid_year_date: string|null, self_due: string|null, review_due: string|null, status: string, scale: string}, parts: list<array{key: string, label: string, weight: int}>, competencies: list<array{id: int, name: string, description: string|null}>, scores: list<array{rater: string, label: string, by: string|null, tasks: float|null, competencies: array<string, float|null>, attendance: float|null, comments: array<string, string|null>, overall: string|null, total: float|null}>, final_score: float|null, rating: string|null, self_comment: string|null, decision_comment: string|null, mid_year_comment: string|null, mid_year_by: string|null, mid_year_at: string|null, employee_comment: string|null, employee_agrees: bool|null, acknowledged_at: string|null, submitted_at: string|null, my_rater: string|null, waiting_for: string|null, steps: list<array{name: string, status: string, by: string|null, at: string|null, comment: string|null}>, tasks: array{total: int, done: int, on_time: int, overdue: int, average_progress: float|null, on_time_rate: float|null, suggested: float|null, list: list<array{id: int, title: string, status: string, status_label: string, progress: int, due_date: string|null}>}, can: array{self: bool, withdraw: bool, review: bool, mid_year: bool, acknowledge: bool, cancel: bool}}}
     */
    public function show(Request $request, Appraisal $appraisal): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->canView($user, $appraisal), 404);
        $a = $appraisal->load(['cycle', 'scores.user', 'employee.jobPosition', 'department', 'midYearBy', 'workflow.steps.actor']);
        $isEmployee = $this->access->isEmployee($user, $a);
        $finished = in_array($a->status, ['acknowledge', 'done'], true);
        $rater = $this->access->raterFor($user, $a);
        $scale = $a->cycle->scale();
        $raters = collect(array_keys(AppraisalScore::RATERS))->filter(fn ($r) => $r === 'self' || ! $isEmployee || $finished)
            ->filter(fn ($r) => $a->scores->where('rater', $r)->isNotEmpty())->values();
        $instance = $a->workflow;

        return response()->json(['data' => [
            'id' => $a->id, 'status' => $a->status, 'status_label' => Appraisal::STATUS_LABELS[$a->status] ?? $a->status,
            'employee' => $a->employee ? ['id' => $a->employee->id, 'name' => $a->employee->full_name, 'employee_no' => $a->employee->employee_no, 'position' => $a->employee->jobPosition?->title] : null,
            'department' => $a->department?->name,
            'cycle' => ['id' => $a->cycle->id, 'name' => $a->cycle->name, 'start_date' => $a->cycle->start_date->toDateString(), 'end_date' => $a->cycle->end_date->toDateString(),
                'mid_year_date' => $a->cycle->mid_year_date?->toDateString(), 'self_due' => $a->cycle->self_due?->toDateString(), 'review_due' => $a->cycle->review_due?->toDateString(),
                'status' => $a->cycle->status, 'scale' => $scale],
            'parts' => array_map(fn ($p) => ['key' => (string) $p['key'], 'label' => (string) $p['label'], 'weight' => (int) $p['weight']], $a->cycle->parts()),
            'competencies' => array_map(fn ($c) => ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'description' => $c['description'] ?? null], $a->cycle->config['competencies'] ?? []),
            'scores' => $raters->map(fn ($r) => $this->raterScores($a, $r, $scale))->values(),
            'final_score' => $isEmployee && ! $finished ? null : $a->final_score,
            'rating' => $isEmployee && ! $finished ? null : $a->rating,
            'self_comment' => $a->self_comment, 'decision_comment' => $a->decision_comment,
            'mid_year_comment' => $a->mid_year_comment, 'mid_year_by' => $a->midYearBy?->display_name, 'mid_year_at' => $a->mid_year_at?->toIso8601String(),
            'employee_comment' => $a->employee_comment, 'employee_agrees' => $a->employee_agrees, 'acknowledged_at' => $a->acknowledged_at?->toIso8601String(),
            'submitted_at' => $a->submitted_at?->toIso8601String(),
            'my_rater' => $rater,
            'waiting_for' => $a->status === 'review' ? $instance?->steps->firstWhere('status', 'pending')?->name : null,
            'steps' => $instance ? $instance->steps->sortBy('step_order')->map(fn ($s) => ['name' => $s->name, 'status' => $s->status, 'by' => $s->actor?->display_name,
                'at' => $s->acted_at?->toIso8601String(), 'comment' => $s->comment])->values() : [],
            'tasks' => $this->scoring->tasks($a),
            'can' => [
                'self' => $isEmployee && in_array($a->status, ['open', 'returned'], true) && $a->cycle->status === 'open',
                'withdraw' => $isEmployee && $a->status === 'review' && ! $instance?->steps->contains('status', 'approved'),
                'review' => $rater !== null,
                'mid_year' => $this->access->canMidYear($user, $a),
                'acknowledge' => $isEmployee && $a->status === 'acknowledge',
                'cancel' => $this->access->manages($user) && ! in_array($a->status, ['done', 'cancelled'], true),
            ],
        ]]);
    }

    /**
     * Save the employee's self-assessment; `submit` sends it for review.
     *
     * @response array{data: array{id: int, status: string}}
     */
    public function self(Request $request, Appraisal $appraisal): JsonResponse
    {
        abort_unless($this->access->canView($request->user(), $appraisal), 404);
        $data = $this->validated($request) + $request->validate(['submit' => ['required', 'boolean']]);
        $a = $this->appraisals->saveSelf($appraisal, $request->user(), $data['scores'] ?? [], $data['comment'] ?? null, (bool) $data['submit']);

        return response()->json(['data' => ['id' => $a->id, 'status' => $a->status]]);
    }

    /**
     * Rate as the reviewer at the current step (supervisor, head of department or HR) and approve, or return it to the employee.
     *
     * @response array{data: array{id: int, status: string}}
     */
    public function review(Request $request, Appraisal $appraisal): JsonResponse
    {
        abort_unless($this->access->canView($request->user(), $appraisal), 404);
        $data = $this->validated($request) + $request->validate(['decision' => ['required', 'in:approve,return']]);
        $a = $this->appraisals->review($appraisal, $request->user(), $data['scores'] ?? [], $data['comment'] ?? null, $data['decision']);

        return response()->json(['data' => ['id' => $a->id, 'status' => $a->status]]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function midYear(Request $request, Appraisal $appraisal): JsonResponse
    {
        abort_unless($this->access->canView($request->user(), $appraisal), 404);
        $a = $this->appraisals->midYear($appraisal, $request->user(), $request->validate(['comment' => ['required', 'string', 'max:3000']])['comment']);

        return response()->json(['data' => ['id' => $a->id, 'status' => $a->status]]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function acknowledge(Request $request, Appraisal $appraisal): JsonResponse
    {
        abort_unless($this->access->canView($request->user(), $appraisal), 404);
        $data = $request->validate(['agrees' => ['required', 'boolean'], 'comment' => ['nullable', 'string', 'max:3000']]);
        $a = $this->appraisals->acknowledge($appraisal, $request->user(), (bool) $data['agrees'], $data['comment'] ?? null);

        return response()->json(['data' => ['id' => $a->id, 'status' => $a->status]]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function withdraw(Request $request, Appraisal $appraisal): JsonResponse
    {
        abort_unless($this->access->canView($request->user(), $appraisal), 404);
        $a = $this->appraisals->withdraw($appraisal, $request->user());

        return response()->json(['data' => ['id' => $a->id, 'status' => $a->status]]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function cancel(Request $request, Appraisal $appraisal): JsonResponse
    {
        abort_unless($this->access->manages($request->user()), 403);
        $a = $this->appraisals->cancel($appraisal);

        return response()->json(['data' => ['id' => $a->id, 'status' => $a->status]]);
    }

    /**
     * The completed appraisal as a PDF.
     */
    public function pdf(Request $request, Appraisal $appraisal): Response
    {
        $user = $request->user();
        abort_unless($this->access->canView($user, $appraisal), 404);
        abort_unless(in_array($appraisal->status, ['acknowledge', 'done'], true) || ! $this->access->isEmployee($user, $appraisal), 403);
        $a = $appraisal->load(['cycle', 'scores.user', 'employee.jobPosition', 'department', 'workflow.steps.actor']);
        $scale = $a->cycle->scale();

        return app(PdfDocument::class)->view($user, 'pdf.appraisal', 'Performance appraisal', [
            'a' => $a, 'parts' => $a->cycle->parts(), 'competencies' => $a->cycle->config['competencies'] ?? [], 'scale' => $scale,
            'scores' => collect(array_keys(AppraisalScore::RATERS))->filter(fn ($r) => $a->scores->where('rater', $r)->isNotEmpty())->map(fn ($r) => $this->raterScores($a, $r, $scale))->values()->all(),
            'tasks' => $this->scoring->tasks($a),
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'scores' => ['nullable', 'array'],
            'scores.tasks' => ['nullable', 'numeric'],
            'scores.attendance' => ['nullable', 'numeric'],
            'scores.competencies' => ['nullable', 'array'],
            'scores.competencies.*' => ['nullable', 'numeric'],
            'scores.comments' => ['nullable', 'array'],
            'scores.comments.*' => ['nullable', 'string', 'max:2000'],
            'comment' => ['nullable', 'string', 'max:3000'],
        ]);
    }

    private function raterScores(Appraisal $a, string $rater, string $scale): array
    {
        $rows = $a->scores->where('rater', $rater);
        $one = fn (string $part) => $this->scoring->toScale($rows->firstWhere('part', $part)?->score, $scale);
        $parts = $this->scoring->partScores($a, $rater);

        return [
            'rater' => $rater, 'label' => AppraisalScore::RATERS[$rater], 'by' => $rows->first()?->user?->display_name,
            'tasks' => $one('tasks'),
            'competencies' => $rows->where('part', 'competencies')->mapWithKeys(fn ($r) => [(string) $r->appraisal_competency_id => $this->scoring->toScale($r->score, $scale)])->all(),
            'attendance' => $one('attendance'),
            'comments' => $rows->whereIn('part', ['tasks', 'attendance'])->mapWithKeys(fn ($r) => [$r->part => $r->comment])->all(),
            'overall' => $rows->firstWhere('part', 'overall')?->comment,
            'total' => $this->scoring->final($a, $parts),
        ];
    }
}
