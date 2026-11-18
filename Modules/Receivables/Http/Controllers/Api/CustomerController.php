<?php

namespace Modules\Receivables\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Receivables\Actions\ManageCustomers;
use Modules\Receivables\Http\Requests\CustomerRequest;
use Modules\Receivables\Models\CreditNote;
use Modules\Receivables\Models\Customer;
use Modules\Receivables\Models\Invoice;
use Modules\Receivables\Models\Receipt;
use Modules\Receivables\Support\Ageing;
use Modules\Receivables\Support\ReceivableAccess;

/**
 * @tags Receivables: customers
 */
class CustomerController extends Controller
{
    public function __construct(private readonly ReceivableAccess $access, private readonly ManageCustomers $customers) {}

    /**
     * Customers with what they owe.
     *
     * @response array{data: list<array{id: int, code: string, name: string, kind: string, kind_label: string, phone: string|null, email: string|null, tin: string|null, terms_days: int|null, credit_limit: float|null, on_hold: bool, is_active: bool, balance: float, overdue: float}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('search', description: 'Code, name, phone or TIN.', type: 'string')]
    #[QueryParameter('kind', type: 'string')]
    #[QueryParameter('active', type: 'boolean')]
    #[QueryParameter('sort', description: '`name`, `code`, `balance`; prefix `-` for descending.', type: 'string', default: 'name')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $sortParam = (string) $request->query('sort', 'name');
        $sort = in_array(ltrim($sortParam, '-'), ['name', 'code', 'balance'], true) ? ltrim($sortParam, '-') : 'name';
        $open = "FROM invoices i WHERE i.customer_id = customers.id AND i.status IN ('open', 'partial')";
        $page = Customer::query()->select('customers.*')
            ->selectRaw("(SELECT COALESCE(SUM(i.total - i.paid - i.credited), 0) {$open}) as balance")
            ->selectRaw("(SELECT COALESCE(SUM(i.total - i.paid - i.credited), 0) {$open} AND i.due_date < ?) as overdue", [today()->toDateString()])
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")->orWhere('tin', 'like', "%{$s}%")))
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy($sort, str_starts_with($sortParam, '-') ? 'desc' : 'asc')->orderBy('id')
            ->paginate(min((int) $request->query('per_page', 10), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (Customer $c) => $this->row($c) + ['balance' => round((float) $c->balance, 2), 'overdue' => round((float) $c->overdue, 2)])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /**
     * A customer with the account statement (running balance) and ageing of unpaid invoices.
     *
     * @response array{data: array{id: int, code: string, name: string, kind: string, kind_label: string, phone: string|null, email: string|null, tin: string|null, terms_days: int|null, credit_limit: float|null, on_hold: bool, is_active: bool, address: string|null, contact_person: string|null}, credit: array{available: float|null, exposure: float, overdue_days: int}, ageing: array{current: float, d30: float, d60: float, d90: float, over90: float, total: float}, statement: list<array{date: string, type: string, number: string, link: string, description: string, debit: float, credit: float, balance: float}>, open_invoices: list<array{id: int, number: string, date: string, due_date: string, total: float, balance: float, overdue_days: int}>}
     */
    public function show(Request $request, Customer $customer): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $invoices = Invoice::where('customer_id', $customer->id)->whereIn('status', Invoice::ISSUED)->get();
        $entries = collect()
            ->concat($invoices->map(fn ($i) => ['date' => $i->date->toDateString(), 'type' => 'Invoice', 'number' => $i->number, 'link' => "/receivables/invoices/{$i->id}",
                'description' => $i->reference ?: 'Invoice', 'debit' => (float) $i->total, 'credit' => 0.0, 'order' => $i->issued_at?->timestamp ?? 0]))
            ->concat(Receipt::where('customer_id', $customer->id)->where('kind', 'invoice')->whereIn('status', ['posted', 'void_pending'])->get()
                ->map(fn ($r) => ['date' => $r->date->toDateString(), 'type' => 'Receipt', 'number' => $r->number, 'link' => "/receivables/receipts/{$r->id}",
                    'description' => Receipt::METHODS[$r->method] ?? $r->method, 'debit' => 0.0, 'credit' => (float) $r->amount, 'order' => $r->created_at->timestamp]))
            ->concat(CreditNote::with('invoice')->whereHas('invoice', fn ($q) => $q->where('customer_id', $customer->id))->where('status', 'approved')->get()
                ->map(fn ($n) => ['date' => $n->date->toDateString(), 'type' => 'Credit note', 'number' => $n->number, 'link' => "/receivables/invoices/{$n->invoice_id}",
                    'description' => $n->reason, 'debit' => 0.0, 'credit' => (float) $n->amount, 'order' => $n->decided_at?->timestamp ?? 0]))
            ->sortBy([['date', 'asc'], ['order', 'asc']])->values();
        $running = 0.0;
        $statement = $entries->map(function ($e) use (&$running) {
            $running = round($running + $e['debit'] - $e['credit'], 2);
            unset($e['order']);

            return $e + ['balance' => $running];
        });
        $open = $invoices->whereIn('status', ['open', 'partial'])->sortBy('due_date');

        return response()->json([
            'data' => $this->row($customer) + ['address' => $customer->address, 'contact_person' => $customer->contact_person],
            'credit' => app(\Modules\Receivables\Support\CreditControl::class)->position($customer),
            'ageing' => Ageing::sum($open),
            'statement' => $statement->values(),
            'open_invoices' => $open->map(fn ($i) => ['id' => $i->id, 'number' => $i->number, 'date' => $i->date->toDateString(), 'due_date' => $i->due_date->toDateString(),
                'total' => (float) $i->total, 'balance' => $i->balance(), 'overdue_days' => max(0, (int) $i->due_date->diffInDays(today(), false))])->values(),
        ]);
    }

    /** @response array{data: array{id: int, code: string, name: string}} */
    public function store(CustomerRequest $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'bill') || $this->access->can($request->user(), 'receipts'), 403);
        $c = $this->customers->save(null, $request->validated());

        return response()->json(['data' => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name]], 201);
    }

    /** @response array{data: array{id: int, code: string, name: string}} */
    public function update(CustomerRequest $request, Customer $customer): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'bill'), 403);
        $c = $this->customers->save($customer, $request->validated());

        return response()->json(['data' => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name]]);
    }

    private function row(Customer $c): array
    {
        return ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'kind' => $c->kind, 'kind_label' => Customer::KINDS[$c->kind] ?? $c->kind, 'phone' => $c->phone,
            'email' => $c->email, 'tin' => $c->tin, 'terms_days' => $c->terms_days, 'credit_limit' => $c->credit_limit, 'on_hold' => (bool) $c->on_hold, 'is_active' => (bool) $c->is_active];
    }
}
