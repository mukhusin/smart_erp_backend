<?php

namespace Modules\Payments\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Budgeting\Services\BudgetService;
use Modules\Payments\Actions\ManageRequests;
use Modules\Payments\Http\Requests\AttachmentRequest;
use Modules\Payments\Http\Requests\PaymentRequestRequest;
use Modules\Payments\Http\Requests\ReasonRequest;
use Modules\Payments\Models\PaymentAttachment;
use Modules\Payments\Models\PaymentRequest;
use Modules\Payments\Models\PaymentRequestPayee;
use Modules\Payments\Models\PaymentVoucher;
use Modules\Payments\Support\PaymentAccess;
use Modules\Payments\Support\PaymentFiles;
use Modules\Workflow\Services\WorkflowEngine;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Payments: requests
 */
class PaymentRequestController extends Controller
{
    public function __construct(private readonly PaymentAccess $access, private readonly ManageRequests $requests) {}

    /**
     * Payment requests the user can see, newest first.
     *
     * @response array{data: list<array{id: int, number: string, title: string, department: string|null, total: float, status: string, status_label: string, over_budget: bool, requested_by: string|null, needed_by: string|null, submitted_at: string|null, waiting_for: string|null, created_at: string}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('search', description: 'Number or title.', type: 'string')]
    #[QueryParameter('status', type: 'string')]
    #[QueryParameter('department_id', type: 'integer')]
    #[QueryParameter('mine', description: 'Only requests I raised.', type: 'boolean')]
    #[QueryParameter('sort', description: '`created_at`, `total`, `number`; prefix `-` for descending.', type: 'string', default: '-created_at')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $departments = $this->access->departments($user);
        $sortParam = (string) $request->query('sort', '-created_at');
        $sort = in_array(ltrim($sortParam, '-'), ['created_at', 'total', 'number'], true) ? ltrim($sortParam, '-') : 'created_at';
        $page = PaymentRequest::with(['department', 'requester', 'workflow.steps'])
            ->where(fn ($q) => $q->where('requested_by', $user->id)->when($departments !== null, fn ($q) => $q->orWhereIn('department_id', $departments)->where('status', '!=', 'draft'), fn ($q) => $q->orWhere('status', '!=', 'draft')))
            ->when($request->boolean('mine'), fn ($q) => $q->where('requested_by', $user->id))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('title', 'like', "%{$s}%")))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('department_id'), fn ($q, $d) => $q->where('department_id', $d))
            ->orderBy($sort, str_starts_with($sortParam, '-') ? 'desc' : 'asc')->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 10), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (PaymentRequest $r) => [
                'id' => $r->id, 'number' => $r->number, 'title' => $r->title, 'department' => $r->department?->name, 'total' => (float) $r->total,
                'status' => $r->status, 'status_label' => PaymentRequest::STATUS_LABELS[$r->status] ?? $r->status, 'over_budget' => (bool) $r->over_budget,
                'requested_by' => $r->requester?->display_name, 'needed_by' => $r->needed_by?->toDateString(), 'submitted_at' => $r->submitted_at?->toIso8601String(),
                'waiting_for' => $r->status === 'pending' ? $r->workflow?->steps->firstWhere('status', 'pending')?->name : null, 'created_at' => $r->created_at->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /**
     * A payment request with its budget lines, payees, documents, vouchers and what the user can do.
     *
     * @response array{data: array{id: int, number: string, title: string, purpose: string|null, department_id: int|null, department: string|null, budget: string|null, needed_by: string|null, total: float, status: string, status_label: string, over_budget: bool, requested_by: string|null, submitted_at: string|null, decision_comment: string|null, waiting_for: string|null, lines: list<array{id: int, budget_item_id: int|null, label: string, description: string|null, amount: float, available: float}>, payees: list<array{id: int, name: string, kind: string, kind_label: string, employee_id: int|null, method: string, method_label: string, bank_name: string|null, account_name: string|null, account_number: string|null, mobile_number: string|null, destination: string, amount: float, withholding: float, net: float}>, attachments: list<array{id: int, name: string, size: int, uploaded_by: string|null, created_at: string}>, vouchers: list<array{id: int, number: string, date: string, status: string, status_label: string, net: float}>, can: array{edit: bool, submit: bool, withdraw: bool, cancel: bool, delete: bool, voucher: bool}}}
     */
    public function show(Request $request, PaymentRequest $paymentRequest, BudgetService $budgets): JsonResponse
    {
        $r = $paymentRequest->load(['lines.item.account', 'lines.item.subActivity', 'lines.item.activity', 'payees', 'attachments.uploader', 'vouchers', 'department', 'budget', 'requester', 'workflow.steps']);
        $user = $request->user();
        $this->authorizeView($user, $r);
        $mine = $r->requested_by === $user->id;
        $finance = $this->access->finance($user);
        $figures = $budgets->figures($r->lines->pluck('item')->filter());

        return response()->json(['data' => [
            'id' => $r->id, 'number' => $r->number, 'title' => $r->title, 'purpose' => $r->purpose, 'department_id' => $r->department_id, 'department' => $r->department?->name,
            'budget' => $r->budget?->title, 'needed_by' => $r->needed_by?->toDateString(), 'total' => (float) $r->total, 'status' => $r->status,
            'status_label' => PaymentRequest::STATUS_LABELS[$r->status] ?? $r->status, 'over_budget' => (bool) $r->over_budget, 'requested_by' => $r->requester?->display_name,
            'submitted_at' => $r->submitted_at?->toIso8601String(), 'decision_comment' => $r->decision_comment,
            'waiting_for' => $r->status === 'pending' ? $r->workflow?->steps->firstWhere('status', 'pending')?->name : null,
            'lines' => $r->lines->map(fn ($l) => ['id' => $l->id, 'budget_item_id' => $l->budget_item_id, 'label' => $l->item?->label() ?? ($l->description ?: '—'), 'description' => $l->description,
                'amount' => $l->amount, 'available' => (float) ($figures[$l->budget_item_id]['available'] ?? 0)])->values(),
            'payees' => $r->payees->map(fn (PaymentRequestPayee $p) => ['id' => $p->id, 'name' => $p->name, 'kind' => $p->kind, 'kind_label' => PaymentRequestPayee::KINDS[$p->kind] ?? $p->kind,
                'employee_id' => $p->employee_id, 'method' => $p->method, 'method_label' => PaymentRequestPayee::METHODS[$p->method] ?? $p->method, 'bank_name' => $p->bank_name,
                'account_name' => $p->account_name, 'account_number' => $p->account_number, 'mobile_number' => $p->mobile_number, 'destination' => $p->destination(),
                'amount' => $p->amount, 'withholding' => $p->withholding, 'net' => $p->net()])->values(),
            'attachments' => $r->attachments->map(fn (PaymentAttachment $a) => ['id' => $a->id, 'name' => $a->name, 'size' => (int) $a->size, 'uploaded_by' => $a->uploader?->display_name,
                'created_at' => $a->created_at->toIso8601String()])->values(),
            'vouchers' => $r->vouchers->map(fn (PaymentVoucher $v) => ['id' => $v->id, 'number' => $v->number, 'date' => $v->date->toDateString(), 'status' => $v->status,
                'status_label' => PaymentVoucher::STATUS_LABELS[$v->status] ?? $v->status, 'net' => (float) $v->net])->values(),
            'can' => [
                'edit' => $r->editable() && $mine, 'submit' => $r->editable() && $mine, 'withdraw' => $r->status === 'pending' && $mine,
                'cancel' => ($r->editable() && ($mine || $finance)) || ($r->status === 'approved' && $finance), 'delete' => $r->status === 'draft' && $mine,
                'voucher' => $r->status === 'approved' && $finance,
            ],
        ]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function store(PaymentRequestRequest $request): JsonResponse
    {
        $data = $request->validated();
        abort_unless($this->access->canRequest($request->user(), (int) $data['department_id']), 403, 'You can only raise payment requests for your departments.');
        $r = $this->requests->save(null, $data, $request->user());

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]], 201);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function update(PaymentRequestRequest $request, PaymentRequest $paymentRequest): JsonResponse
    {
        $data = $request->validated();
        abort_unless($paymentRequest->requested_by === $request->user()->id && $this->access->canRequest($request->user(), (int) $data['department_id']), 403);
        $r = $this->requests->save($paymentRequest, $data, $request->user());

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]]);
    }

    public function destroy(Request $request, PaymentRequest $paymentRequest): JsonResponse
    {
        $this->requests->delete($paymentRequest, $request->user());

        return response()->json(['message' => 'Payment request deleted.']);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function submit(Request $request, PaymentRequest $paymentRequest): JsonResponse
    {
        abort_unless($paymentRequest->requested_by === $request->user()->id, 403);
        $r = $this->requests->submit($paymentRequest->load('lines'), $request->user());

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdraw(Request $request, PaymentRequest $paymentRequest): JsonResponse
    {
        $r = $this->requests->withdraw($paymentRequest, $request->user());

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function cancel(ReasonRequest $request, PaymentRequest $paymentRequest): JsonResponse
    {
        $this->authorizeView($request->user(), $paymentRequest);
        $r = $this->requests->cancel($paymentRequest, $request->user(), $this->access->finance($request->user()), $request->validated('reason'));

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]]);
    }

    /** @response array{data: array{id: int, name: string, size: int}} */
    public function attach(AttachmentRequest $request, PaymentRequest $paymentRequest): JsonResponse
    {
        abort_unless($paymentRequest->requested_by === $request->user()->id, 403);
        $a = $this->requests->attach($paymentRequest, $request->file('file'), $request->user());

        return response()->json(['data' => ['id' => $a->id, 'name' => $a->name, 'size' => (int) $a->size]], 201);
    }

    public function file(Request $request, PaymentRequest $paymentRequest, PaymentAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->payment_request_id === $paymentRequest->id, 404);
        $this->authorizeView($request->user(), $paymentRequest);

        return PaymentFiles::response($attachment->path, $attachment->name);
    }

    public function detach(Request $request, PaymentRequest $paymentRequest, PaymentAttachment $attachment): JsonResponse
    {
        abort_unless($attachment->payment_request_id === $paymentRequest->id, 404);
        abort_unless($paymentRequest->requested_by === $request->user()->id, 403);
        $this->requests->detach($paymentRequest, $attachment);

        return response()->json(['message' => 'Document removed.']);
    }

    private function authorizeView($user, PaymentRequest $r): void
    {
        abort_unless($r->requested_by === $user->id || ($r->status !== 'draft' && ($this->access->canView($user, $r->department_id) || $this->approver($user, $r))), 403);
    }

    private function approver($user, PaymentRequest $r): bool
    {
        $instance = $r->workflow;

        return $instance && ($instance->steps()->where('acted_by', $user->id)->exists() || app(WorkflowEngine::class)->canAct($user, $instance));
    }
}
