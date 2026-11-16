<?php

namespace Modules\Receivables\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Receivables\Models\CreditNote;
use Modules\Receivables\Models\Customer;
use Modules\Receivables\Models\Invoice;
use Modules\Receivables\Models\Receipt;
use Modules\Receivables\Support\Ageing;
use Modules\Receivables\Support\ReceivableAccess;

/**
 * @tags Receivables: reports
 */
class ReceivableReportController extends Controller
{
    public function __construct(private readonly ReceivableAccess $access) {}

    /**
     * Statistics: what customers owe, overdue, invoiced and received by month, ageing, top debtors, receipts by method and what waits.
     *
     * @response array{totals: array{outstanding: float, overdue: float, invoiced_month: float, received_month: float, direct_month: float, customers_owing: int, unpaid_invoices: int}, ageing: array{current: float, d30: float, d60: float, d90: float, over90: float, total: float}, months: list<array{label: string, invoiced: float, received: float, direct: float}>, top_debtors: list<array{id: int, name: string, kind_label: string, balance: float, overdue: float}>, methods: list<array{label: string, amount: float}>, kinds: list<array{label: string, balance: float}>, pending: array{invoices: int, credit_notes: int, voids: int}}
     */
    public function dashboard(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $open = Invoice::with('customer')->whereIn('status', ['open', 'partial'])->get();
        $start = today()->startOfMonth()->subMonths(11);
        $invoices = Invoice::whereIn('status', Invoice::ISSUED)->whereDate('date', '>=', $start)->get(['date', 'total']);
        $receipts = Receipt::whereIn('status', ['posted', 'void_pending'])->whereDate('date', '>=', $start)->get(['date', 'amount', 'kind', 'method']);
        $months = [];
        for ($m = 0; $m < 12; $m++) {
            $d = $start->copy()->addMonths($m);
            $key = $d->format('Y-m');
            $months[$key] = ['label' => $d->format('M y'), 'invoiced' => 0.0, 'received' => 0.0, 'direct' => 0.0];
        }
        foreach ($invoices as $i) {
            $months[$i->date->format('Y-m')]['invoiced'] += $i->total;
        }
        foreach ($receipts as $r) {
            $months[$r->date->format('Y-m')][$r->kind === 'invoice' ? 'received' : 'direct'] += $r->amount;
        }
        $thisMonth = $months[today()->format('Y-m')];
        $byCustomer = $open->groupBy('customer_id');

        return response()->json([
            'totals' => [
                'outstanding' => round($open->sum(fn ($i) => $i->balance()), 2), 'overdue' => round($open->filter(fn ($i) => $i->due_date->lt(today()))->sum(fn ($i) => $i->balance()), 2),
                'invoiced_month' => round($thisMonth['invoiced'], 2), 'received_month' => round($thisMonth['received'], 2), 'direct_month' => round($thisMonth['direct'], 2),
                'customers_owing' => $byCustomer->count(), 'unpaid_invoices' => $open->count(),
            ],
            'ageing' => Ageing::sum($open),
            'months' => array_values(array_map(fn ($m) => ['label' => $m['label'], 'invoiced' => round($m['invoiced'], 2), 'received' => round($m['received'], 2), 'direct' => round($m['direct'], 2)], $months)),
            'top_debtors' => $byCustomer->map(fn ($g) => ['id' => (int) $g->first()->customer_id, 'name' => (string) $g->first()->customer?->name,
                'kind_label' => Customer::KINDS[$g->first()->customer?->kind] ?? '', 'balance' => round($g->sum(fn ($i) => $i->balance()), 2),
                'overdue' => round($g->filter(fn ($i) => $i->due_date->lt(today()))->sum(fn ($i) => $i->balance()), 2)])->sortByDesc('balance')->take(8)->values(),
            'methods' => $receipts->groupBy('method')->map(fn ($g, $m) => ['label' => Receipt::METHODS[$m] ?? $m, 'amount' => round($g->sum('amount'), 2)])->sortByDesc('amount')->values(),
            'kinds' => $open->groupBy(fn ($i) => $i->customer?->kind)->map(fn ($g, $k) => ['label' => Customer::KINDS[$k] ?? 'Other', 'balance' => round($g->sum(fn ($i) => $i->balance()), 2)])->sortByDesc('balance')->values(),
            'pending' => ['invoices' => Invoice::where('status', 'pending')->count(), 'credit_notes' => CreditNote::where('status', 'pending')->count(), 'voids' => Receipt::where('status', 'void_pending')->count()],
        ]);
    }

    /**
     * Unpaid balances per customer by age (days past the due date).
     *
     * @response array{data: list<array{id: int, code: string, name: string, kind_label: string, current: float, d30: float, d60: float, d90: float, over90: float, total: float}>, totals: array{current: float, d30: float, d60: float, d90: float, over90: float, total: float}}
     */
    public function ageing(Request $request): JsonResponse
    {
        abort_unless($this->access->sees($request->user()), 403);
        $open = Invoice::with('customer')->whereIn('status', ['open', 'partial'])->get();
        $rows = $open->groupBy('customer_id')->map(fn ($g) => ['id' => (int) $g->first()->customer_id, 'code' => (string) $g->first()->customer?->code, 'name' => (string) $g->first()->customer?->name,
            'kind_label' => Customer::KINDS[$g->first()->customer?->kind] ?? ''] + Ageing::sum($g))->sortByDesc('total')->values();

        return response()->json(['data' => $rows, 'totals' => Ageing::sum($open)]);
    }
}
