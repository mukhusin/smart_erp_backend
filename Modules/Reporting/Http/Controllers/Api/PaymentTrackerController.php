<?php

namespace Modules\Reporting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CompanyContext;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Core\Services\AccessService;
use Modules\Payments\Models\PaymentRequest;
use Modules\Payments\Models\PaymentVoucher;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Modules\Workflow\Services\StepApprovers;

/**
 * Where each payment request is, who it waits for and for how long; and how fast each approver acts on payments.
 *
 * @tags Reports: Executive dashboard
 */
class PaymentTrackerController extends Controller
{
    public const PROCESSES = ['payment-request', 'payment-voucher', 'payment-list', 'imprest-retirement', 'petty-cash-voucher', 'petty-cash-replenishment', 'payment-void'];

    private const OPEN = ['pending', 'approved', 'vouchered'];

    /** @var array<string, list<string>> */
    private array $holders = [];

    public function __construct(private readonly StepApprovers $approvers) {}

    /**
     * Payment requests with their stage.
     *
     * @response array{data: list<array{id: int, number: string, title: string, kind: string, department: string|null, requested_by: string|null, amount: float, submitted_at: string|null, age_days: int|null, status: string, status_label: string, stage: string, waiting_on: list<string>, waiting_days: int|null, link: string}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null, ageing: list<array{label: string, value: int}>}}
     */
    #[QueryParameter('scope', type: 'string', description: 'open (default) or all')]
    #[QueryParameter('search', type: 'string')]
    #[QueryParameter('sort', type: 'string', description: 'submitted_at or total; - for descending')]
    #[QueryParameter('page', type: 'integer')]
    #[QueryParameter('per_page', type: 'integer')]
    public function index(Request $request): JsonResponse
    {
        abort_unless(app(AccessService::class)->can($request->user(), 'payments.view'), 403);
        $f = $request->validate(['scope' => ['nullable', 'in:open,all'], 'search' => ['nullable', 'string', 'max:100'], 'sort' => ['nullable', 'string', 'max:20'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $sortParam = $f['sort'] ?? 'submitted_at';
        $sort = in_array(ltrim($sortParam, '-'), ['submitted_at', 'total'], true) ? ltrim($sortParam, '-') : 'submitted_at';
        $page = PaymentRequest::with(['department', 'requester.employee', 'workflow.steps', 'vouchers.workflow.steps'])
            ->where('status', '!=', 'draft')
            ->when(($f['scope'] ?? 'open') === 'open', fn ($q) => $q->whereIn('status', self::OPEN))
            ->when($f['search'] ?? null, fn ($q, $t) => $q->where(fn ($q) => $q->where('number', 'like', "%{$t}%")->orWhere('title', 'like', "%{$t}%")
                ->orWhereHas('department', fn ($q) => $q->where('name', 'like', "%{$t}%"))))
            ->orderBy($sort, str_starts_with($sortParam, '-') ? 'desc' : 'asc')->orderBy('id')
            ->paginate($f['per_page'] ?? 10);

        return response()->json([
            'data' => collect($page->items())->map(fn (PaymentRequest $r) => $this->row($r))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'from' => $page->firstItem(), 'to' => $page->lastItem(), 'ageing' => $this->ageing()],
        ]);
    }

    /**
     * How fast each approver acts on payment approvals (last 90 days) and what waits for them now.
     *
     * @response array{data: list<array{user_id: int, name: string, acted: int, avg_hours: float|null, slowest_hours: float|null, waiting: int, oldest_days: int|null}>}
     */
    public function approvers(Request $request): JsonResponse
    {
        abort_unless(app(AccessService::class)->can($request->user(), 'payments.view'), 403);
        $acted = WorkflowInstanceStep::query()->whereHas('instance', fn ($q) => $q->whereIn('process', self::PROCESSES))
            ->whereNotNull('acted_by')->whereNotNull('pending_since')->where('acted_at', '>=', now()->subDays(90))->get(['acted_by', 'acted_at', 'pending_since'])
            ->groupBy('acted_by')->map(function (Collection $steps) {
                $hours = $steps->map(fn ($s) => max(0, $s->pending_since->diffInMinutes($s->acted_at)) / 60);

                return ['acted' => $steps->count(), 'avg_hours' => round($hours->avg(), 1), 'slowest_hours' => round($hours->max(), 1)];
            });
        $waiting = [];
        foreach ($this->pendingSteps() as [$instance, $step]) {
            foreach ($this->approvers->for($instance, $step) as $user) {
                $w = $waiting[$user->id] ?? ['count' => 0, 'oldest' => null];
                $w['count']++;
                $w['oldest'] = $w['oldest'] && $w['oldest']->lt($step->pending_since) ? $w['oldest'] : $step->pending_since;
                $waiting[$user->id] = $w;
            }
        }
        $users = User::with('employee')->whereIn('id', $acted->keys()->merge(array_keys($waiting))->unique())->get()->keyBy('id');

        return response()->json(['data' => $users->map(fn (User $u) => [
            'user_id' => $u->id, 'name' => $u->display_name,
            'acted' => $acted[$u->id]['acted'] ?? 0, 'avg_hours' => $acted[$u->id]['avg_hours'] ?? null, 'slowest_hours' => $acted[$u->id]['slowest_hours'] ?? null,
            'waiting' => $waiting[$u->id]['count'] ?? 0, 'oldest_days' => isset($waiting[$u->id]['oldest']) ? (int) $waiting[$u->id]['oldest']->diffInDays(now()) : null,
        ])->sortBy(fn ($r) => [$r['avg_hours'] === null ? 1 : 0, $r['avg_hours']])->values()]);
    }

    private function row(PaymentRequest $r): array
    {
        [$stage, $waitingOn, $since] = $this->stage($r);

        return [
            'id' => $r->id, 'number' => $r->number, 'title' => $r->title, 'kind' => $r->kind, 'department' => $r->department?->name, 'requested_by' => $r->requester?->display_name,
            'amount' => (float) $r->total, 'submitted_at' => $r->submitted_at?->toDateTimeString(), 'age_days' => $r->submitted_at ? (int) $r->submitted_at->diffInDays(now()) : null,
            'status' => $r->status, 'status_label' => PaymentRequest::STATUS_LABELS[$r->status] ?? $r->status, 'stage' => $stage, 'waiting_on' => $waitingOn,
            'waiting_days' => $since ? (int) $since->diffInDays(now()) : null, 'link' => '/payments/requests/'.$r->id,
        ];
    }

    /** @return array{0: string, 1: list<string>, 2: Carbon|null} */
    private function stage(PaymentRequest $r): array
    {
        $voucher = $r->vouchers->sortByDesc('id')->first(fn (PaymentVoucher $v) => ! in_array($v->status, ['cancelled', 'rejected', 'voided'], true));

        return match (true) {
            $r->status === 'pending' => $this->waiting('Approval', $r->workflow, $r->submitted_at),
            $r->status === 'approved' => ['Approved, waiting for a payment voucher', $this->withPermission('payments.vouchers'), $r->decided_at],
            $r->status === 'vouchered' && $voucher?->status === 'pending' => $this->waiting('Voucher approval', $voucher->workflow, $voucher->created_at),
            $r->status === 'vouchered' && $voucher?->status === 'approved' => ['Voucher approved, waiting for payment', $this->withPermission('payments.pay'), $voucher->decided_at],
            default => [PaymentRequest::STATUS_LABELS[$r->status] ?? $r->status, [], null],
        };
    }

    /** @return array{0: string, 1: list<string>, 2: Carbon|null} */
    private function waiting(string $label, ?WorkflowInstance $wf, ?Carbon $fallback): array
    {
        $step = $wf?->status === WorkflowInstance::PENDING ? $wf->steps->firstWhere('status', WorkflowInstanceStep::PENDING) : null;
        if (! $step) {
            return [$label, [], $fallback];
        }

        return ["$label: {$step->name}", $this->approvers->for($wf, $step)->map->display_name->values()->all(), $step->pending_since ?? $fallback];
    }

    /** @return list<string> */
    private function withPermission(string $permission): array
    {
        $access = app(AccessService::class);

        return $this->holders[$permission] ??= User::with('employee')->where('company_id', app(CompanyContext::class)->id())->where('is_active', true)->get()
            ->filter(fn ($u) => $access->can($u, $permission))->map->display_name->values()->all();
    }

    /** @return list<array{0: WorkflowInstance, 1: WorkflowInstanceStep}> */
    private function pendingSteps(): array
    {
        return WorkflowInstance::with('steps')->whereIn('process', self::PROCESSES)->where('status', WorkflowInstance::PENDING)->get()
            ->map(fn ($wf) => [$wf, $wf->steps->firstWhere('status', WorkflowInstanceStep::PENDING)])->filter(fn ($p) => $p[1]?->pending_since)->values()->all();
    }

    /** @return list<array{label: string, value: int}> */
    private function ageing(): array
    {
        $days = collect($this->pendingSteps())->map(fn ($p) => (int) $p[1]->pending_since->diffInDays(now()));

        return [
            ['label' => 'Under 3 days', 'value' => $days->filter(fn ($d) => $d < 3)->count()],
            ['label' => '3–7 days', 'value' => $days->filter(fn ($d) => $d >= 3 && $d <= 7)->count()],
            ['label' => '8–14 days', 'value' => $days->filter(fn ($d) => $d >= 8 && $d <= 14)->count()],
            ['label' => 'Over 14 days', 'value' => $days->filter(fn ($d) => $d > 14)->count()],
        ];
    }
}
