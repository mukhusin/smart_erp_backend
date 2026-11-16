<?php

namespace Modules\Payments\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payments\Actions\ManageVouchers;
use Modules\Payments\Http\Requests\ReasonRequest;
use Modules\Payments\Http\Requests\VoucherRequest;
use Modules\Payments\Models\PaymentRequest;
use Modules\Payments\Models\PaymentRequestPayee;
use Modules\Payments\Models\PaymentVoucher;
use Modules\Payments\Support\PaymentAccess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * @tags Payments: vouchers
 */
class PaymentVoucherController extends Controller
{
    public function __construct(private readonly PaymentAccess $access, private readonly ManageVouchers $vouchers) {}

    /**
     * Payment vouchers, newest first. `payable=1` gives approved vouchers not yet on a payment list.
     *
     * @response array{data: list<array{id: int, number: string, date: string, request_id: int, request_number: string|null, title: string|null, department: string|null, bank_id: int, bank: string|null, gross: float, withholding: float, net: float, status: string, status_label: string, waiting_for: string|null, prepared_by: string|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('search', description: 'Voucher or request number, title.', type: 'string')]
    #[QueryParameter('status', type: 'string')]
    #[QueryParameter('cash_bank_account_id', type: 'integer')]
    #[QueryParameter('payable', type: 'boolean')]
    #[QueryParameter('sort', description: '`date`, `net`, `number`; prefix `-` for descending.', type: 'string', default: '-date')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->seesAll($request->user()), 403);
        $sortParam = (string) $request->query('sort', '-date');
        $sort = in_array(ltrim($sortParam, '-'), ['date', 'net', 'number'], true) ? ltrim($sortParam, '-') : 'date';
        $page = PaymentVoucher::with(['request.department', 'bank', 'preparer', 'workflow.steps'])
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")
                ->orWhereHas('request', fn ($r) => $r->where('number', 'like', "%{$s}%")->orWhere('title', 'like', "%{$s}%"))))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('cash_bank_account_id'), fn ($q, $b) => $q->where('cash_bank_account_id', $b))
            ->when($request->boolean('payable'), fn ($q) => $q->where('status', 'approved')->whereHas('request.payees', fn ($p) => $p->whereRaw('amount - withholding > 0')
                ->whereDoesntHave('listItems', fn ($i) => PaymentRequestPayee::settling($i))))
            ->orderBy($sort, str_starts_with($sortParam, '-') ? 'desc' : 'asc')->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 10), 500));

        return response()->json([
            'data' => collect($page->items())->map(fn (PaymentVoucher $v) => [
                'id' => $v->id, 'number' => $v->number, 'date' => $v->date->toDateString(), 'request_id' => $v->payment_request_id, 'request_number' => $v->request?->number,
                'title' => $v->request?->title, 'department' => $v->request?->department?->name, 'bank_id' => $v->cash_bank_account_id, 'bank' => $v->bank?->name,
                'gross' => (float) $v->gross, 'withholding' => (float) $v->withholding, 'net' => (float) $v->net, 'status' => $v->status,
                'status_label' => PaymentVoucher::STATUS_LABELS[$v->status] ?? $v->status,
                'waiting_for' => $v->status === 'pending' ? $v->workflow?->steps->firstWhere('status', 'pending')?->name : null, 'prepared_by' => $v->preparer?->display_name,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /**
     * A voucher with its request, payees, journal and payment.
     *
     * @response array{data: array{id: int, number: string, date: string, description: string|null, request: array{id: int, number: string, title: string, department: string|null}, bank: array{id: int, name: string}|null, gross: float, withholding: float, net: float, status: string, status_label: string, waiting_for: string|null, decision_comment: string|null, prepared_by: string|null, journal: array{id: int, reference_no: string|null}|null, lines: list<array{label: string, description: string|null, amount: float}>, payees: list<array{id: int, name: string, method_label: string, destination: string, amount: float, withholding: float, net: float, reference: string|null, list_number: string|null, payment_status: string|null, failure_reason: string|null}>, can: array{withdraw: bool, void: bool}}}
     */
    public function show(Request $request, PaymentVoucher $voucher): JsonResponse
    {
        $user = $request->user();
        $v = $voucher->load(['request.department', 'request.lines.item.account', 'request.lines.item.subActivity', 'request.lines.item.activity', 'request.payees', 'bank', 'journal', 'preparer', 'workflow.steps', 'listItems.list']);
        abort_unless($this->access->seesAll($user) || ($v->workflow && ($v->workflow->steps->contains('acted_by', $user->id) || app(WorkflowEngine::class)->canAct($user, $v->workflow))), 403);
        $paid = $v->listItems->filter(fn ($i) => $i->list?->status === 'approved')->sortBy('id')->keyBy('payment_request_payee_id');

        return response()->json(['data' => [
            'id' => $v->id, 'number' => $v->number, 'date' => $v->date->toDateString(), 'description' => $v->description,
            'request' => ['id' => $v->request->id, 'number' => $v->request->number, 'title' => $v->request->title, 'department' => $v->request->department?->name],
            'bank' => $v->bank ? ['id' => $v->bank->id, 'name' => $v->bank->name] : null, 'gross' => (float) $v->gross, 'withholding' => (float) $v->withholding, 'net' => (float) $v->net,
            'status' => $v->status, 'status_label' => PaymentVoucher::STATUS_LABELS[$v->status] ?? $v->status,
            'waiting_for' => $v->status === 'pending' ? $v->workflow?->steps->firstWhere('status', 'pending')?->name : null, 'decision_comment' => $v->decision_comment,
            'prepared_by' => $v->preparer?->display_name, 'journal' => $v->journal ? ['id' => $v->journal->id, 'reference_no' => $v->journal->reference_no] : null,
            'lines' => $v->request->lines->map(fn ($l) => ['label' => $l->item?->label() ?? '—', 'description' => $l->description, 'amount' => $l->amount])->values(),
            'payees' => $v->request->payees->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'method_label' => $p::METHODS[$p->method] ?? $p->method, 'destination' => $p->destination(),
                'amount' => $p->amount, 'withholding' => $p->withholding, 'net' => $p->net(), 'reference' => $paid[$p->id]->reference ?? null, 'list_number' => $paid[$p->id]?->list?->number ?? null,
                'payment_status' => isset($paid[$p->id]) ? (\Modules\Payments\Models\PaymentListItem::STATUS_LABELS[$paid[$p->id]->status] ?? $paid[$p->id]->status) : null,
                'failure_reason' => $paid[$p->id]->failure_reason ?? null])->values(),
            'can' => ['withdraw' => $v->status === 'pending' && $v->prepared_by === $user->id, 'void' => in_array($v->status, ['approved', 'paid'], true) && $this->access->finance($user) && ! $v->inOpenList()],
        ]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function store(VoucherRequest $request, PaymentRequest $paymentRequest): JsonResponse
    {
        abort_unless($this->access->finance($request->user()), 403, 'Vouchers are prepared by finance.');
        $v = $this->vouchers->create($paymentRequest->load('payees'), $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $v->id, 'number' => $v->number, 'status' => $v->status]], 201);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdraw(Request $request, PaymentVoucher $voucher): JsonResponse
    {
        $v = $this->vouchers->withdraw($voucher, $request->user());

        return response()->json(['data' => ['id' => $v->id, 'number' => $v->number, 'status' => $v->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function void(ReasonRequest $request, PaymentVoucher $voucher): JsonResponse
    {
        abort_unless($this->access->finance($request->user()), 403);
        $v = $this->vouchers->void($voucher, $request->user(), $request->validated('reason'));

        return response()->json(['data' => ['id' => $v->id, 'number' => $v->number, 'status' => $v->status]]);
    }
}
