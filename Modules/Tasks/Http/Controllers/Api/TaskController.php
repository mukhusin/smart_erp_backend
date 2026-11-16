<?php

namespace Modules\Tasks\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Tasks\Actions\ManageTasks;
use Modules\Tasks\Http\Requests\TaskRequest;
use Modules\Tasks\Http\Resources\TaskResource;
use Modules\Tasks\Models\Task;
use Modules\Tasks\Models\TaskUpdate;
use Modules\Tasks\Support\TaskAccess;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Tasks
 */
class TaskController extends Controller
{
    private const WITH = ['employee', 'department', 'activity', 'parent', 'creator'];

    public function __construct(private readonly TaskAccess $access, private readonly ManageTasks $tasks) {}

    /**
     * List tasks.
     *
     * `view=mine` the user's own tasks, `team` tasks of staff they head or gave, `all` every task (tasks.view-all).
     */
    #[QueryParameter('view', description: '`mine`, `team` or `all`.', type: 'string', default: 'mine')]
    #[QueryParameter('filter[search]', description: 'Title or employee name/number.', type: 'string')]
    #[QueryParameter('filter[status]', description: 'A status, `open` or `overdue`.', type: 'string')]
    #[QueryParameter('filter[employee_id]', type: 'integer')]
    #[QueryParameter('filter[department_id]', type: 'integer')]
    #[QueryParameter('filter[task_activity_id]', type: 'integer')]
    #[QueryParameter('filter[parent_id]', type: 'integer')]
    #[QueryParameter('sort', description: '`due_date`, `title`, `priority`, `progress`, `created_at`; prefix `-` for descending.', type: 'string', default: 'due_date')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Max 100.', type: 'integer', default: 10)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $view = in_array($request->query('view'), ['team', 'all'], true) ? $request->query('view') : 'mine';
        $base = $this->access->visible(Task::query()->with(self::WITH)->withCount(['subtasks', 'updates as pending_updates_count' => fn ($q) => $q->where('status', 'pending')]), $request->user(), $view)
            ->when(! $request->has('filter.parent_id'), fn ($q) => $q->whereNull('parent_id'));

        $tasks = QueryBuilder::for($base)
            ->allowedFilters(
                AllowedFilter::callback('search', fn ($q, $v) => $q->where(fn ($q) => $q->where('title', 'like', "%{$v}%")
                    ->orWhereHas('employee', fn ($e) => $e->withoutGlobalScopes()->where(fn ($e) => $e->where('fname', 'like', "%{$v}%")->orWhere('lname', 'like', "%{$v}%")->orWhere('employee_no', 'like', "%{$v}%"))))),
                AllowedFilter::callback('status', fn ($q, $v) => match ($v) {
                    'open' => $q->whereIn('status', Task::OPEN),
                    'overdue' => $q->whereIn('status', Task::OPEN)->whereDate('due_date', '<', today()),
                    default => $q->where('status', $v),
                }),
                AllowedFilter::exact('employee_id'),
                AllowedFilter::exact('department_id'),
                AllowedFilter::exact('task_activity_id'),
                AllowedFilter::exact('parent_id'),
            )
            ->allowedSorts('due_date', 'title', 'progress', 'created_at', AllowedSort::callback('priority', fn ($q, bool $desc) => $q->orderByRaw("CASE priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'normal' THEN 2 ELSE 1 END ".($desc ? 'DESC' : 'ASC'))))
            ->defaultSort('due_date')
            ->paginate(min((int) $request->query('per_page', 10), 100))
            ->withQueryString();

        return TaskResource::collection($tasks);
    }

    /**
     * Counts for the user's tasks and, for heads, their team's.
     *
     * @response array{data: array{mine: array{open: int, overdue: int, review: int, done_month: int}, team: array{open: int, overdue: int, review: int, done_month: int, pending_updates: int}|null, can_assign: bool, sees_all: bool}}
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $counts = fn (string $view) => [
            'open' => $this->access->visible(Task::query(), $user, $view)->whereIn('status', Task::OPEN)->count(),
            'overdue' => $this->access->visible(Task::query(), $user, $view)->whereIn('status', Task::OPEN)->whereDate('due_date', '<', today())->count(),
            'review' => $this->access->visible(Task::query(), $user, $view)->where('status', 'review')->count(),
            'done_month' => $this->access->visible(Task::query(), $user, $view)->where('status', 'done')->where('completed_at', '>=', now()->startOfMonth())->count(),
        ];
        $head = $this->access->canAssign($user) || $this->access->seesAll($user);

        return response()->json(['data' => [
            'mine' => $counts('mine'),
            'team' => $head ? $counts('team') + ['pending_updates' => TaskUpdate::where('status', 'pending')->whereIn('task_id', $this->access->visible(Task::query(), $user, 'team')->select('id'))->count()] : null,
            'can_assign' => $this->access->canAssign($user),
            'sees_all' => $this->access->seesAll($user),
        ]]);
    }

    /**
     * Add a task.
     *
     * Without `employee_ids` the task is for the user. Heads (tasks.assign) can give it to several staff at once (one task each).
     */
    public function store(TaskRequest $request): JsonResponse
    {
        $tasks = $this->tasks->create($request->validated(), $request->user());

        return TaskResource::collection($tasks->each->load(self::WITH))->response()->setStatusCode(201);
    }

    /**
     * A task with its progress updates, comments and sub-tasks.
     *
     * @response array{data: TaskResource, updates: list<array{id: int, note: string, progress: int, status: string, status_label: string, decision_comment: string|null, by: string|null, created_at: string, decided_at: string|null, can_withdraw: bool}>, comments: list<array{id: int, body: string, by: string|null, created_at: string}>, subtasks: list<array{id: int, title: string, status: string, status_label: string, progress: int, due_date: string|null, employee: string|null}>}
     */
    public function show(Request $request, Task $task): JsonResponse
    {
        abort_unless($this->access->canView($request->user(), $task), 404);
        $task->load([...self::WITH, 'updates.user', 'comments.user', 'subtasks.employee'])->loadCount(['subtasks', 'updates as pending_updates_count' => fn ($q) => $q->where('status', 'pending')]);
        $userId = $request->user()->id;

        return response()->json([
            'data' => (new TaskResource($task))->resolve($request),
            'updates' => $task->updates->map(fn (TaskUpdate $u) => [
                'id' => $u->id, 'note' => $u->note, 'progress' => (int) $u->progress, 'status' => $u->status, 'status_label' => TaskUpdate::STATUS_LABELS[$u->status] ?? $u->status,
                'decision_comment' => $u->decision_comment, 'by' => $u->user?->display_name, 'created_at' => $u->created_at->toIso8601String(),
                'decided_at' => $u->decided_at?->toIso8601String(), 'can_withdraw' => $u->status === 'pending' && $u->user_id === $userId,
            ])->values(),
            'comments' => $task->comments->map(fn ($c) => ['id' => $c->id, 'body' => $c->body, 'by' => $c->user?->display_name, 'created_at' => $c->created_at->toIso8601String()])->values(),
            'subtasks' => $task->subtasks->map(fn (Task $t) => [
                'id' => $t->id, 'title' => $t->title, 'status' => $t->status, 'status_label' => Task::STATUS_LABELS[$t->status] ?? $t->status,
                'progress' => (int) $t->progress, 'due_date' => $t->due_date?->toDateString(), 'employee' => $t->employee?->full_name,
            ])->values(),
        ]);
    }

    public function update(TaskRequest $request, Task $task): TaskResource
    {
        abort_unless($this->access->canView($request->user(), $task), 404);

        return new TaskResource($this->tasks->update($task, $request->validated(), $request->user())->load(self::WITH));
    }

    /**
     * Start, finish, review (accept or return), cancel or reopen a task.
     */
    public function action(Request $request, Task $task, string $action): TaskResource
    {
        $user = $request->user();
        abort_unless($this->access->canView($user, $task), 404);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000'], 'accept' => [$action === 'review' ? 'required' : 'nullable', 'boolean']]);
        $task = match ($action) {
            'start' => $this->tasks->start($task, $user),
            'complete' => $this->tasks->complete($task, $user, $data['comment'] ?? null),
            'review' => $this->tasks->review($task, $user, (bool) $data['accept'], $data['comment'] ?? null),
            'cancel' => $this->tasks->cancel($task, $user),
            'reopen' => $this->tasks->reopen($task, $user),
            default => abort(404),
        };

        return new TaskResource($task->load(self::WITH));
    }

    /**
     * Report progress (what was done and the % reached). It counts once the supervisor approves.
     *
     * @response array{data: array{id: int, progress: int, status: string}}
     */
    public function report(Request $request, Task $task): JsonResponse
    {
        abort_unless($this->access->canView($request->user(), $task), 404);
        $data = $request->validate(['note' => ['required', 'string', 'max:2000'], 'progress' => ['required', 'integer', 'between:1,100']]);
        $update = $this->tasks->addUpdate($task, $request->user(), $data['note'], (int) $data['progress']);

        return response()->json(['data' => ['id' => $update->id, 'progress' => (int) $update->progress, 'status' => $update->status]], 201);
    }

    /**
     * Withdraw a progress update still waiting for approval.
     *
     * @response array{message: string}
     */
    public function withdrawUpdate(Request $request, Task $task, TaskUpdate $update): JsonResponse
    {
        abort_unless($update->task_id === $task->id && $this->access->canView($request->user(), $task), 404);
        $this->tasks->withdrawUpdate($update, $request->user());

        return response()->json(['message' => 'Update withdrawn.']);
    }

    /**
     * @response array{data: array{id: int, body: string, by: string|null, created_at: string}}
     */
    public function comment(Request $request, Task $task): JsonResponse
    {
        abort_unless($this->access->canView($request->user(), $task), 404);
        $c = $this->tasks->comment($task, $request->user(), $request->validate(['body' => ['required', 'string', 'max:2000']])['body']);

        return response()->json(['data' => ['id' => $c->id, 'body' => $c->body, 'by' => $request->user()->display_name, 'created_at' => $c->created_at->toIso8601String()]], 201);
    }

    public function checklist(Request $request, Task $task): TaskResource
    {
        abort_unless($this->access->canView($request->user(), $task), 404);
        $data = $request->validate(['checklist' => ['present', 'array', 'max:50'], 'checklist.*.text' => ['required', 'string', 'max:200'], 'checklist.*.done' => ['sometimes', 'boolean']]);

        return new TaskResource($this->tasks->checklist($task, $request->user(), $data['checklist'])->load(self::WITH));
    }
}
