<?php

namespace Modules\Receivables\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Receivables\Actions\ManageReceipts;
use Modules\Receivables\Http\Requests\ReceiptRequest;
use Modules\Receivables\Http\Requests\VoidRequest;
use Modules\Receivables\Models\Receipt;
use Modules\Receivables\Support\ReceivableAccess;

/**
 * @tags Receivables: receipts
 */
class ReceiptController extends Controller
{
    public function __construct(private readonly ReceivableAccess $access, private readonly ManageReceipts $receipts) {}

    /**
     * Money received, newest first.
     *
     * @response array{data: list<array{id: int, number: string, date: string, kind: string, kind_label: string, payer_name: string, customer: string|null, bank: string|null, method_label: string, reference: string|null, amount: float, status: string, status_label: string}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('search', description: 'Number, payer or reference.', type: 'string')]
    #[QueryParameter('kind', description: '`invoice` or `direct`.', type: 'string')]
    #[QueryParameter('status', type: 'string')]
    #[QueryParameter('customer_id', type: 'integer')]
    #[QueryParameter('from', type: 'string')]
    #[QueryParameter('to', type: 'string')]
    #[QueryParameter('sort', description: '`date`, `amount`, `number`; prefix `-` for descending.', type: 'string', default: '-date')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $sortParam = (string) $request->query('sort', '-date');
        $sort = in_array(ltrim($sortParam, '-'), ['date', 'amount', 'number'], true) ? ltrim($sortParam, '-') : 'date';
        $page = Receipt::with(['customer', 'bank'])->inAuditPeriod('date')
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('payer_name', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")))
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('customer_id'), fn ($q, $c) => $q->where('customer_id', $c))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->orderBy($sort, str_starts_with($sortParam, '-') ? 'desc' : 'asc')->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 10), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (Receipt $r) => [
                'id' => $r->id, 'number' => $r->number, 'date' => $r->date->toDateString(), 'kind' => $r->kind, 'kind_label' => Receipt::KINDS[$r->kind] ?? $r->kind,
                'payer_name' => $r->payer_name, 'customer' => $r->customer?->name, 'bank' => $r->bank?->name, 'method_label' => Receipt::METHODS[$r->method] ?? $r->method,
                'reference' => $r->reference, 'amount' => (float) $r->amount, 'status' => $r->status, 'status_label' => Receipt::STATUS_LABELS[$r->status] ?? $r->status,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /**
     * A receipt with the invoices it paid or what it was for.
     *
     * @response array{data: array{id: int, number: string, date: string, kind: string, kind_label: string, payer_name: string, customer: array{id: int, name: string}|null, bank: string|null, method_label: string, reference: string|null, notes: string|null, amount: float, status: string, status_label: string, void_reason: string|null, created_by: string|null, journal: array{id: int, reference_no: string|null}|null, allocations: list<array{invoice_id: int, number: string|null, amount: float}>, lines: list<array{description: string, account: string|null, department: string|null, amount: float, tax_amount: float}>, can: array{void: bool}}}
     */
    public function show(Request $request, Receipt $receipt): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $r = $receipt->load(['customer', 'bank', 'allocations.invoice', 'lines.account', 'lines.department', 'creator', 'journal']);

        return response()->json(['data' => [
            'id' => $r->id, 'number' => $r->number, 'date' => $r->date->toDateString(), 'kind' => $r->kind, 'kind_label' => Receipt::KINDS[$r->kind] ?? $r->kind,
            'payer_name' => $r->payer_name, 'customer' => $r->customer ? ['id' => $r->customer->id, 'name' => $r->customer->name] : null, 'bank' => $r->bank?->name,
            'method_label' => Receipt::METHODS[$r->method] ?? $r->method, 'reference' => $r->reference, 'notes' => $r->notes, 'amount' => (float) $r->amount,
            'status' => $r->status, 'status_label' => Receipt::STATUS_LABELS[$r->status] ?? $r->status, 'void_reason' => $r->void_reason, 'created_by' => $r->creator?->display_name,
            'journal' => $r->journal ? ['id' => $r->journal->id, 'reference_no' => $r->journal->reference_no] : null,
            'allocations' => $r->allocations->map(fn ($a) => ['invoice_id' => $a->invoice_id, 'number' => $a->invoice?->number, 'amount' => $a->amount])->values(),
            'lines' => $r->lines->map(fn ($l) => ['description' => $l->description, 'account' => $l->account ? "{$l->account->code} {$l->account->name}" : null, 'department' => $l->department?->name, 'amount' => $l->amount, 'tax_amount' => (float) $l->tax_amount])->values(),
            'can' => ['void' => $r->status === 'posted' && $this->access->can($request->user(), 'receipts')],
        ]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function store(ReceiptRequest $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'receipts'), 403);
        $r = $this->receipts->create($request->validated(), $request->user());

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]], 201);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function void(VoidRequest $request, Receipt $receipt): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'receipts'), 403);
        $r = $this->receipts->requestVoid($receipt, $request->user(), $request->validated('reason'));

        return response()->json(['data' => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status]]);
    }
}
