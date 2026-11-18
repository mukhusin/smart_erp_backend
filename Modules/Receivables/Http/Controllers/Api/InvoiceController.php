<?php

namespace Modules\Receivables\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Receivables\Actions\ManageCreditNotes;
use Modules\Receivables\Actions\ManageInvoices;
use Modules\Receivables\Http\Requests\CreditNoteRequest;
use Modules\Receivables\Http\Requests\InvoiceRequest;
use Modules\Receivables\Http\Requests\VoidRequest;
use Modules\Receivables\Models\CreditNote;
use Modules\Receivables\Models\Invoice;
use Modules\Receivables\Models\Receipt;
use Modules\Receivables\Support\ReceivableAccess;

/**
 * @tags Receivables: invoices
 */
class InvoiceController extends Controller
{
    public function __construct(private readonly ReceivableAccess $access, private readonly ManageInvoices $invoices, private readonly ManageCreditNotes $notes) {}

    /**
     * Customer invoices, newest first.
     *
     * @response array{data: list<array{id: int, number: string, date: string, due_date: string, customer_id: int, customer: string|null, reference: string|null, total: float, balance: float, status: string, status_label: string, overdue: bool, waiting_for: string|null}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('search', description: 'Number, reference or customer.', type: 'string')]
    #[QueryParameter('status', description: 'A status, or `unpaid` for open and part paid.', type: 'string')]
    #[QueryParameter('customer_id', type: 'integer')]
    #[QueryParameter('overdue', type: 'boolean')]
    #[QueryParameter('sort', description: '`date`, `due_date`, `total`, `number`; prefix `-` for descending.', type: 'string', default: '-date')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $sortParam = (string) $request->query('sort', '-date');
        $sort = in_array(ltrim($sortParam, '-'), ['date', 'due_date', 'total', 'number'], true) ? ltrim($sortParam, '-') : 'date';
        $page = Invoice::with(['customer', 'workflow.steps'])->inAuditPeriod('date')
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))))
            ->when($request->query('status'), fn ($q, $s) => $s === 'unpaid' ? $q->whereIn('status', ['open', 'partial']) : $q->where('status', $s))
            ->when($request->query('customer_id'), fn ($q, $c) => $q->where('customer_id', $c))
            ->when($request->boolean('overdue'), fn ($q) => $q->whereIn('status', ['open', 'partial'])->whereDate('due_date', '<', today()))
            ->orderBy($sort, str_starts_with($sortParam, '-') ? 'desc' : 'asc')->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 10), 200));

        return response()->json([
            'data' => collect($page->items())->map(fn (Invoice $i) => [
                'id' => $i->id, 'number' => $i->number, 'date' => $i->date->toDateString(), 'due_date' => $i->due_date->toDateString(), 'customer_id' => $i->customer_id,
                'customer' => $i->customer?->name, 'reference' => $i->reference, 'total' => (float) $i->total, 'balance' => $i->balance(), 'status' => $i->status,
                'status_label' => Invoice::STATUS_LABELS[$i->status] ?? $i->status, 'overdue' => in_array($i->status, ['open', 'partial'], true) && $i->due_date->lt(today()),
                'waiting_for' => $i->status === 'pending' ? $i->workflow?->steps->firstWhere('status', 'pending')?->name : null,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /**
     * An invoice with its lines, receipts, credit notes and what the user can do.
     *
     * @response array{data: array{id: int, number: string, date: string, due_date: string, customer: array{id: int, code: string, name: string, tin: string|null, address: string|null}, reference: string|null, notes: string|null, total: float, paid: float, credited: float, balance: float, status: string, status_label: string, overdue: bool, decision_comment: string|null, waiting_for: string|null, created_by: string|null, journal: array{id: int, reference_no: string|null}|null, lines: list<array{id: int, description: string, account_id: int, account: string|null, department_id: int|null, department: string|null, budget_item_id: int|null, quantity: float, unit_price: float, amount: float}>, receipts: list<array{id: int, number: string, date: string, amount: float, status: string}>, credit_notes: list<array{id: int, number: string, date: string, amount: float, reason: string, status: string, status_label: string, can_withdraw: bool}>, can: array{edit: bool, issue: bool, withdraw: bool, void: bool, delete: bool, credit: bool, receive: bool}}}
     */
    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $user = $request->user();
        $i = $invoice->load(['customer', 'lines.account', 'lines.department', 'allocations.receipt', 'creditNotes', 'creator', 'journal', 'workflow.steps']);
        $bill = $this->access->can($user, 'bill');
        $open = in_array($i->status, ['open', 'partial'], true);

        return response()->json(['data' => [
            'id' => $i->id, 'number' => $i->number, 'date' => $i->date->toDateString(), 'due_date' => $i->due_date->toDateString(),
            'customer' => ['id' => $i->customer->id, 'code' => $i->customer->code, 'name' => $i->customer->name, 'tin' => $i->customer->tin, 'address' => $i->customer->address],
            'reference' => $i->reference, 'notes' => $i->notes, 'total' => (float) $i->total, 'paid' => (float) $i->paid, 'credited' => (float) $i->credited, 'balance' => $i->balance(),
            'status' => $i->status, 'status_label' => Invoice::STATUS_LABELS[$i->status] ?? $i->status, 'overdue' => $open && $i->due_date->lt(today()),
            'decision_comment' => $i->decision_comment, 'waiting_for' => $i->status === 'pending' ? $i->workflow?->steps->firstWhere('status', 'pending')?->name : null,
            'created_by' => $i->creator?->display_name, 'journal' => $i->journal ? ['id' => $i->journal->id, 'reference_no' => $i->journal->reference_no] : null,
            'lines' => $i->lines->map(fn ($l) => ['id' => $l->id, 'description' => $l->description, 'account_id' => $l->account_id, 'account' => $l->account ? "{$l->account->code} {$l->account->name}" : null,
                'department_id' => $l->department_id, 'department' => $l->department?->name, 'budget_item_id' => $l->budget_item_id, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'amount' => $l->amount])->values(),
            'receipts' => $i->allocations->filter(fn ($a) => $a->receipt)->map(fn ($a) => ['id' => $a->receipt->id, 'number' => $a->receipt->number, 'date' => $a->receipt->date->toDateString(),
                'amount' => $a->amount, 'status' => Receipt::STATUS_LABELS[$a->receipt->status] ?? $a->receipt->status])->values(),
            'credit_notes' => $i->creditNotes->map(fn (CreditNote $n) => ['id' => $n->id, 'number' => $n->number, 'date' => $n->date->toDateString(), 'amount' => $n->amount, 'reason' => $n->reason,
                'status' => $n->status, 'status_label' => CreditNote::STATUS_LABELS[$n->status] ?? $n->status, 'can_withdraw' => $n->status === 'pending' && $n->created_by === $user->id])->values(),
            'can' => [
                'edit' => $i->editable() && $bill, 'issue' => $i->editable() && $bill, 'withdraw' => $i->status === 'pending' && $i->created_by === $user->id,
                'void' => in_array($i->status, Invoice::ISSUED, true) && $i->paid == 0 && $i->credited == 0 && $this->access->can($user, 'manage'),
                'delete' => $i->status === 'draft' && $bill, 'credit' => $open && $bill, 'receive' => $open && $this->access->can($user, 'receipts'),
            ],
        ]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function store(InvoiceRequest $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'bill'), 403);
        $i = $this->invoices->save(null, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $i->id, 'number' => $i->number, 'status' => $i->status]], 201);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function update(InvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'bill'), 403);
        $i = $this->invoices->save($invoice, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $i->id, 'number' => $i->number, 'status' => $i->status]]);
    }

    public function destroy(Request $request, Invoice $invoice): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'bill'), 403);
        $this->invoices->delete($invoice);

        return response()->json(['message' => 'Invoice deleted.']);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function issue(Request $request, Invoice $invoice): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'bill'), 403);
        $i = $this->invoices->issue($invoice, $request->user());

        return response()->json(['data' => ['id' => $i->id, 'number' => $i->number, 'status' => $i->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdraw(Request $request, Invoice $invoice): JsonResponse
    {
        $i = $this->invoices->withdraw($invoice, $request->user());

        return response()->json(['data' => ['id' => $i->id, 'number' => $i->number, 'status' => $i->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function void(VoidRequest $request, Invoice $invoice): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'manage'), 403);
        $i = $this->invoices->void($invoice, $request->user(), $request->validated('reason'));

        return response()->json(['data' => ['id' => $i->id, 'number' => $i->number, 'status' => $i->status]]);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function credit(CreditNoteRequest $request, Invoice $invoice): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'bill'), 403);
        $n = $this->notes->create($invoice, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $n->id, 'number' => $n->number, 'status' => $n->status]], 201);
    }

    /** @response array{data: array{id: int, number: string, status: string}} */
    public function withdrawCredit(Request $request, Invoice $invoice, CreditNote $creditNote): JsonResponse
    {
        abort_unless($creditNote->invoice_id === $invoice->id, 404);
        $n = $this->notes->withdraw($creditNote, $request->user());

        return response()->json(['data' => ['id' => $n->id, 'number' => $n->number, 'status' => $n->status]]);
    }
}
