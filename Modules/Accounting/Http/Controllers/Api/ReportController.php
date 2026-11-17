<?php

namespace Modules\Accounting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CompanyContext;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Models\JournalEntry;

/**
 * @tags Accounting: reports
 */
class ReportController extends Controller
{
    private function lines(): Builder
    {
        return DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.company_id', app(CompanyContext::class)->id())->whereIn('e.status', ['posted', 'reversed']);
    }

    private function yearStart(string $date): string
    {
        return FiscalYear::containing($date)?->start_date->toDateString() ?? Carbon::parse($date)->startOfYear()->toDateString();
    }

    /**
     * Figures for the accounting dashboard.
     *
     * @response array{data: array{year: string|null, cash: list<array{id: int, name: string, kind: string, balance: float}>, revenue: float, expense: float, surplus: float, pending_journals: int, draft_journals: int, open_period: string|null, months: list<array{label: string, revenue: float, expense: float}>}}
     */
    public function summary(): JsonResponse
    {
        $today = today()->toDateString();
        $year = FiscalYear::containing($today);
        $from = $year?->start_date->toDateString() ?? today()->startOfYear()->toDateString();
        $byType = $this->lines()->where('e.entry_type', '!=', 'closing')->join('accounts as a', 'a.id', '=', 'l.account_id')->whereBetween('e.date', [$from, $today])
            ->groupBy('a.type')->selectRaw('a.type, SUM(l.debit) as d, SUM(l.credit) as c')->get()->keyBy('type');
        $revenue = round((float) ($byType['revenue']->c ?? 0) - (float) ($byType['revenue']->d ?? 0), 2);
        $expense = round((float) ($byType['expense']->d ?? 0) - (float) ($byType['expense']->c ?? 0), 2);
        $cashTotals = $this->lines()->whereIn('l.account_id', CashBankAccount::pluck('account_id'))->groupBy('l.account_id')->selectRaw('l.account_id, SUM(l.debit) - SUM(l.credit) as b')->pluck('b', 'account_id');
        $months = $this->lines()->where('e.entry_type', '!=', 'closing')->join('accounts as a', 'a.id', '=', 'l.account_id')->whereIn('a.type', ['revenue', 'expense'])->whereBetween('e.date', [$from, $today])
            ->groupBy('a.type', 'e.date')->selectRaw('a.type, e.date, SUM(l.debit) as d, SUM(l.credit) as c')->get()
            ->map(fn ($r) => (object) ['type' => $r->type, 'ym' => substr((string) $r->date, 0, 7), 'd' => (float) $r->d, 'c' => (float) $r->c]);
        $period = $year?->periods()->where('status', 'open')->orderBy('number')->first();

        return response()->json(['data' => [
            'year' => $year?->name,
            'cash' => CashBankAccount::where('is_active', true)->orderBy('name')->get()->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'kind' => $c->kind, 'balance' => round((float) ($cashTotals[$c->account_id] ?? 0), 2)])->values(),
            'revenue' => $revenue, 'expense' => $expense, 'surplus' => round($revenue - $expense, 2),
            'pending_journals' => JournalEntry::where('status', 'pending')->count(), 'draft_journals' => JournalEntry::whereIn('status', ['draft', 'rejected'])->count(),
            'open_period' => $period?->name,
            'months' => $months->groupBy('ym')->sortKeys()->map(fn ($g, $ym) => [
                'label' => Carbon::parse($ym.'-01')->format('M Y'),
                'revenue' => round((float) $g->where('type', 'revenue')->sum('c') - (float) $g->where('type', 'revenue')->sum('d'), 2),
                'expense' => round((float) $g->where('type', 'expense')->sum('d') - (float) $g->where('type', 'expense')->sum('c'), 2),
            ])->values(),
        ]]);
    }

    /**
     * Trial balance: opening, movements and closing balance per account for the dates. Revenue and expense accounts start
     * the fiscal year at zero.
     *
     * @response array{data: list<array{id: int, code: string, gfs_code: string|null, name: string, type: string, opening: float, debit: float, credit: float, closing: float, closing_debit: float, closing_credit: float}>, totals: array{debit: float, credit: float, closing_debit: float, closing_credit: float}, balanced: bool, from: string, to: string}
     */
    #[QueryParameter('from', type: 'string')]
    #[QueryParameter('to', type: 'string')]
    #[QueryParameter('department_id', type: 'integer')]
    #[QueryParameter('fund_source_id', type: 'integer')]
    #[QueryParameter('include_closing', description: 'Include the year-end closing journal (post-closing trial balance).', type: 'boolean')]
    public function trialBalance(Request $request): JsonResponse
    {
        $to = $request->query('to', today()->toDateString());
        $from = $request->query('from', $this->yearStart($to));
        $yearStart = $this->yearStart($from);
        $filter = fn (Builder $q) => $q->when(! $request->boolean('include_closing'), fn ($q) => $q->where(fn ($w) => $w->where('e.entry_type', '!=', 'closing')->orWhere('e.date', '<', $yearStart)))->when($request->query('department_id'), fn ($q, $d) => $q->where('l.department_id', $d))
            ->when($request->query('fund_source_id'), fn ($q, $f) => $q->where('l.fund_source_id', $f));
        $sum = fn (Builder $q) => $q->groupBy('l.account_id')->selectRaw('l.account_id, SUM(l.debit) as d, SUM(l.credit) as c')->get()->keyBy('account_id');
        $before = $sum($filter($this->lines())->where('e.date', '<', $from));
        $beforeYear = $sum($filter($this->lines())->where('e.date', '<', $yearStart));
        $moves = $sum($filter($this->lines())->whereBetween('e.date', [$from, $to]));
        $rows = Account::where('is_postable', true)->orderBy('code')->get()->map(function (Account $a) use ($before, $beforeYear, $moves) {
            $pnl = in_array($a->type, ['revenue', 'expense'], true);
            $od = (float) ($before[$a->id]->d ?? 0) - ($pnl ? (float) ($beforeYear[$a->id]->d ?? 0) : 0);
            $oc = (float) ($before[$a->id]->c ?? 0) - ($pnl ? (float) ($beforeYear[$a->id]->c ?? 0) : 0);
            $d = (float) ($moves[$a->id]->d ?? 0);
            $c = (float) ($moves[$a->id]->c ?? 0);
            $net = round(($od + $d) - ($oc + $c), 2);

            return ['id' => $a->id, 'code' => $a->code, 'gfs_code' => $a->gfs_code, 'name' => $a->name, 'type' => $a->type,
                'opening' => $a->balance($od, $oc), 'debit' => round($d, 2), 'credit' => round($c, 2), 'closing' => $a->balance($od + $d, $oc + $c),
                'closing_debit' => $net > 0 ? $net : 0.0, 'closing_credit' => $net < 0 ? -$net : 0.0];
        })->filter(fn ($r) => $r['opening'] != 0 || $r['debit'] != 0 || $r['credit'] != 0)->values();
        $totals = ['debit' => round($rows->sum('debit'), 2), 'credit' => round($rows->sum('credit'), 2), 'closing_debit' => round($rows->sum('closing_debit'), 2), 'closing_credit' => round($rows->sum('closing_credit'), 2)];

        return response()->json(['data' => $rows, 'totals' => $totals, 'balanced' => $totals['closing_debit'] === $totals['closing_credit'] && $totals['debit'] === $totals['credit'], 'from' => $from, 'to' => $to]);
    }

    /**
     * General ledger of one account: opening balance, each posted line with a running balance, and closing balance.
     *
     * @response array{data: list<array{journal_id: int, reference_no: string|null, date: string, description: string|null, entry_type: string, department: string|null, fund_source: string|null, debit: float, credit: float, balance: float}>, account: array{id: int, code: string, name: string, type: string}, opening: float, closing: float, total_debit: float, total_credit: float, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('account_id', type: 'integer', required: true)]
    #[QueryParameter('from', type: 'string')]
    #[QueryParameter('to', type: 'string')]
    #[QueryParameter('department_id', type: 'integer')]
    #[QueryParameter('fund_source_id', type: 'integer')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 25)]
    public function ledger(Request $request): JsonResponse
    {
        $account = Account::findOrFail((int) $request->query('account_id'));
        $to = $request->query('to', today()->toDateString());
        $from = $request->query('from', $this->yearStart($to));
        $pnl = in_array($account->type, ['revenue', 'expense'], true);
        $base = fn () => $this->lines()->where('l.account_id', $account->id)
            ->when($request->query('department_id'), fn ($q, $d) => $q->where('l.department_id', $d))
            ->when($request->query('fund_source_id'), fn ($q, $f) => $q->where('l.fund_source_id', $f));
        $openQ = $base()->where('e.date', '<', $from)->when($pnl, fn ($q) => $q->where('e.date', '>=', $this->yearStart($from)));
        $opening = $account->balance((float) (clone $openQ)->sum('l.debit'), (float) (clone $openQ)->sum('l.credit'));
        $range = fn () => $base()->whereBetween('e.date', [$from, $to]);
        $perPage = min((int) $request->query('per_page', 25), 200);
        $pageNo = max((int) $request->query('page', 1), 1);
        $total = $range()->count();
        $priorRows = $range()->orderBy('e.date')->orderBy('e.id')->orderBy('l.line_no')->limit(($pageNo - 1) * $perPage)->select('l.debit', 'l.credit')->get();
        $running = $opening + $account->balance((float) $priorRows->sum('debit'), (float) $priorRows->sum('credit'));
        $rows = $range()->leftJoin('departments as d', 'd.id', '=', 'l.department_id')->leftJoin('fund_sources as f', 'f.id', '=', 'l.fund_source_id')
            ->orderBy('e.date')->orderBy('e.id')->orderBy('l.line_no')->offset(($pageNo - 1) * $perPage)->limit($perPage)
            ->select('e.id as journal_id', 'e.reference_no', 'e.date', 'e.entry_type', DB::raw('COALESCE(l.description, e.description) as description'), 'd.name as department', 'f.code as fund_source', 'l.debit', 'l.credit')->get()
            ->map(function ($r) use (&$running, $account) {
                $running = round($running + $account->balance((float) $r->debit, (float) $r->credit), 2);

                return ['journal_id' => (int) $r->journal_id, 'reference_no' => $r->reference_no, 'date' => Carbon::parse($r->date)->toDateString(), 'description' => $r->description,
                    'entry_type' => $r->entry_type, 'department' => $r->department, 'fund_source' => $r->fund_source, 'debit' => (float) $r->debit, 'credit' => (float) $r->credit, 'balance' => $running];
            });
        $td = (float) $range()->sum('l.debit');
        $tc = (float) $range()->sum('l.credit');
        $last = max((int) ceil($total / $perPage), 1);

        return response()->json([
            'data' => $rows->values(), 'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type],
            'opening' => $opening, 'closing' => round($opening + $account->balance($td, $tc), 2), 'total_debit' => round($td, 2), 'total_credit' => round($tc, 2),
            'meta' => ['current_page' => $pageNo, 'last_page' => $last, 'per_page' => $perPage, 'total' => $total, 'from' => $total ? ($pageNo - 1) * $perPage + 1 : null, 'to' => $total ? min($pageNo * $perPage, $total) : null],
        ]);
    }
}
