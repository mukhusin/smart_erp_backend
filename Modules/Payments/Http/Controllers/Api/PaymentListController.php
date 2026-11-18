<?php

namespace Modules\Payments\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payments\Actions\ManageLists;
use Modules\Payments\Http\Requests\PaymentListRequest;
use Modules\Payments\Http\Requests\ReferencesRequest;
use Modules\Payments\Models\PaymentList;
use Modules\Payments\Models\PaymentListItem;
use Modules\Payments\Http\Requests\FailItemRequest;
use Modules\Payments\Models\PaymentRequestPayee;
use Modules\Payments\Support\PaymentAccess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * @tags Payments: lists
 */
class PaymentListController extends Controller
{
    public function __construct(private readonly PaymentAccess $access, private readonly ManageLists $lists) {}

    /**
     * Payment lists, newest first.
     *
     * @response array{data: list<array{id: int, number: string, date: string, bank: string|null, method: string, method_label: string, total: float, payees: int, status: string, status_label: string, waiting_for: string|null, prepared_by: string|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('search', description: 'List number.', type: 'string')]
    #[QueryParameter('status', type: 'string')]
    #[QueryParameter('sort', description: '`date`, `total`, `number`; prefix `-` for descending.', type: 'string', default: '-date')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->seesAll($request->user()), 403);
        $sortParam = (string) $request->query('sort', '-date');
        $sort = in_array(ltrim($sortParam, '-'), ['date', 'total', 'number'], true) ? ltrim($sortParam, '-') : 'date';
        $page = PaymentList::with(['bank', 'preparer', 'workflow.steps'])->withCount('items')->inAuditPeriod('date')
            ->when($request->query('search'), fn ($q, $s) => $q->where('number', 'like', "%{$s}%"))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy($sort, str_starts_with($sortParam, '-') ? 'desc' : 'asc')->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 10), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (PaymentList $l) => [
                'id' => $l->id, 'number' => $l->number, 'date' => $l->date->toDateString(), 'bank' => $l->bank?->name, 'method' => $l->method,
                'method_label' => PaymentRequestPayee::METHODS[$l->method] ?? $l->method, 'total' => (float) $l->total, 'payees' => (int) $l->items_count,
                'status' => $l->status, 'status_label' => PaymentList::STATUS_LABELS[$l->status] ?? $l->status,
                'waiting_for' => $l->status === 'pending' ? $l->workflow?->steps->firstWhere('status', 'pending')?->name : null, 'prepared_by' => $l->preparer?->display_name,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /**
     * A payment list with each payee to pay and the bank reference.
     *
     * @response array{data: array{id: int, number: string, date: string, bank: array{id: int, name: string}|null, bank_balance: float, method: string, method_label: string, total: float, status: string, status_label: string, waiting_for: string|null, decision_comment: string|null, prepared_by: string|null, journal: array{id: int, reference_no: string|null}|null, items: list<array{id: int, voucher_id: int, voucher_number: string|null, request_number: string|null, payee: string|null, method_label: string, bank_name: string|null, account_name: string|null, account_number: string|null, mobile_number: string|null, amount: float, reference: string|null, status: string, status_label: string, failure_reason: string|null, can_fail: bool}>, can: array{withdraw: bool, references: bool}}}
     */
    public function show(Request $request, PaymentList $list): JsonResponse
    {
        $user = $request->user();
        $l = $list->load(['bank', 'preparer', 'journal', 'workflow.steps', 'items.voucher.request', 'items.payee']);
        abort_unless($this->access->seesAll($user) || ($l->workflow && ($l->workflow->steps->contains('acted_by', $user->id) || app(WorkflowEngine::class)->canAct($user, $l->workflow))), 403);

        return response()->json(['data' => [
            'id' => $l->id, 'number' => $l->number, 'date' => $l->date->toDateString(), 'bank' => $l->bank ? ['id' => $l->bank->id, 'name' => $l->bank->name] : null,
            'bank_balance' => $l->bank ? $this->lists->balance($l->bank) : 0.0, 'method' => $l->method, 'method_label' => PaymentRequestPayee::METHODS[$l->method] ?? $l->method,
            'total' => (float) $l->total, 'status' => $l->status, 'status_label' => PaymentList::STATUS_LABELS[$l->status] ?? $l->status,
            'waiting_for' => $l->status === 'pending' ? $l->workflow?->steps->firstWhere('status', 'pending')?->name : null, 'decision_comment' => $l->decision_comment,
            'prepared_by' => $l->preparer?->display_name, 'journal' => $l->journal ? ['id' => $l->journal->id, 'reference_no' => $l->journal->reference_no] : null,
            'items' => $l->items->map(fn ($i) => ['id' => $i->id, 'voucher_id' => $i->payment_voucher_id, 'voucher_number' => $i->voucher?->number, 'request_number' => $i->voucher?->request?->number,
                'payee' => $i->payee?->name, 'method_label' => PaymentRequestPayee::METHODS[$i->payee?->method] ?? '—', 'bank_name' => $i->payee?->bank_name,
                'account_name' => $i->payee?->account_name, 'account_number' => $i->payee?->account_number, 'mobile_number' => $i->payee?->mobile_number,
                'amount' => $i->amount, 'reference' => $i->reference, 'status' => $i->status, 'status_label' => PaymentListItem::STATUS_LABELS[$i->status] ?? $i->status,
                'failure_reason' => $i->failure_reason, 'can_fail' => $l->status === 'approved' && $i->status === 'paid' && $this->access->cashier($user)])->values(),
            'can' => ['withdraw' => $l->status === 'pending' && $l->prepared_by === $user->id, 'references' => $l->status === 'approved' && $this->access->cashier($user)],
        ]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function store(PaymentListRequest $request): JsonResponse
    {
        abort_unless($this->access->cashier($request->user()), 403, 'Payment lists are prepared by the cashier.');
        $l = $this->lists->create($request->validated(), $request->user());

        return response()->json(['data' => ['id' => $l->id, 'number' => $l->number, 'status' => $l->status]], 201);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdraw(Request $request, PaymentList $list): JsonResponse
    {
        $l = $this->lists->withdraw($list, $request->user());

        return response()->json(['data' => ['id' => $l->id, 'number' => $l->number, 'status' => $l->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function references(ReferencesRequest $request, PaymentList $list): JsonResponse
    {
        abort_unless($this->access->cashier($request->user()), 403);
        $refs = collect($request->validated('references'))->mapWithKeys(fn ($r) => [(int) $r['id'] => $r['reference'] ?? null])->all();
        $l = $this->lists->references($list->load('items'), $refs);

        return response()->json(['data' => ['id' => $l->id, 'number' => $l->number, 'status' => $l->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function fail(FailItemRequest $request, PaymentList $list, PaymentListItem $item): JsonResponse
    {
        abort_unless($this->access->cashier($request->user()), 403);
        $l = $this->lists->markFailed($list, $item, $request->validated('status'), $request->validated('reason'), $request->user());

        return response()->json(['data' => ['id' => $l->id, 'number' => $l->number, 'status' => $l->status]]);
    }
}
