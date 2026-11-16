<?php

namespace Modules\Budgeting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CompanyContext;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Models\BudgetRelease;
use Modules\Budgeting\Models\BudgetTransfer;
use Modules\Budgeting\Models\DepartmentBudget;
use Modules\Budgeting\Support\BudgetAccess;
use Modules\Budgeting\Support\BudgetFigures;

/**
 * @tags Budgeting
 */
class BudgetDashboardController extends Controller
{
    public function __construct(private readonly BudgetAccess $access, private readonly BudgetFigures $figures) {}

    /**
     * Budget statistics for the dashboard: totals, plan against actual by month, departments, spending by account,
     * lines close to or over budget, and what waits for approval. Limited to the user's departments.
     *
     * @response array{budget: array{id: int, title: string, status: string, fiscal_year: string|null, phasing: string}|null, budgets: list<array{id: int, title: string}>, totals: array{revised: float, released: float, reserved: float, committed: float, actual: float, available: float, used_percent: float|null, revenue_budget: float, revenue_actual: float, items: int}, months: list<array{label: string, planned: float, actual: float, revenue_planned: float, revenue_actual: float}>, departments: list<array{id: int, name: string, revised: float, actual: float, held: float, used_percent: float|null}>, accounts: list<array{name: string, actual: float}>, at_risk: list<array{id: int, label: string, department: string|null, revised: float, available: float, used_percent: float|null}>, pending: array{department_budgets: int, transfers: int, releases: int}}
     */
    #[QueryParameter('budget_id', description: 'Defaults to the approved budget of the current fiscal year.', type: 'integer')]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $departments = $this->access->departments($user);
        abort_unless($departments !== [], 403);
        $budgets = Budget::with('fiscalYear')->latest('id')->get();
        $budget = $request->query('budget_id') ? $budgets->firstWhere('id', (int) $request->query('budget_id'))
            : ($budgets->first(fn ($b) => $b->status === 'approved' && $b->fiscalYear?->start_date <= today() && $b->fiscalYear?->end_date >= today()) ?? $budgets->first());
        $list = $budgets->map(fn ($b) => ['id' => $b->id, 'title' => $b->title])->values();
        if (! $budget) {
            return response()->json(['budget' => null, 'budgets' => $list, 'totals' => $this->emptyTotals(), 'months' => [], 'departments' => [], 'accounts' => [], 'at_risk' => [],
                'pending' => ['department_budgets' => 0, 'transfers' => 0, 'releases' => 0]]);
        }

        $items = BudgetItem::where('budget_id', $budget->id)->when($departments !== null, fn ($q) => $q->whereIn('department_id', $departments))->get();
        $rows = $this->figures->items($items);
        $expense = $rows->filter(fn ($r) => ($r['account']['type'] ?? 'expense') !== 'revenue');
        $revenue = $rows->filter(fn ($r) => ($r['account']['type'] ?? null) === 'revenue');
        $t = $this->figures->totals($expense);
        $held = fn ($c) => round($c->sum('reserved') + $c->sum('committed'), 2);
        $used = fn ($revised, $spent) => $revised > 0 ? round($spent / $revised * 100, 1) : null;

        return response()->json([
            'budget' => ['id' => $budget->id, 'title' => $budget->title, 'status' => $budget->status, 'fiscal_year' => $budget->fiscalYear?->name, 'phasing' => $budget->phasing],
            'budgets' => $list,
            'totals' => ['revised' => $t['revised'], 'released' => $t['released'], 'reserved' => $t['reserved'], 'committed' => $t['committed'], 'actual' => $t['actual'],
                'available' => $t['available'], 'used_percent' => $used($t['revised'], $t['actual'] + $t['reserved'] + $t['committed']),
                'revenue_budget' => round($revenue->sum('revised'), 2), 'revenue_actual' => round($revenue->sum('actual'), 2), 'items' => $rows->count()],
            'months' => $this->months($budget, $items),
            'departments' => $expense->groupBy('department_id')->map(fn ($g, $id) => ['id' => (int) $id, 'name' => (string) $g->first()['department'],
                'revised' => round($g->sum('revised'), 2), 'actual' => round($g->sum('actual'), 2), 'held' => $held($g),
                'used_percent' => $used($g->sum('revised'), $g->sum('actual') + $held($g))])->sortByDesc('revised')->values(),
            'accounts' => $expense->groupBy(fn ($r) => $r['account'] ? $r['account']['code'].' '.$r['account']['name'] : 'Other')
                ->map(fn ($g, $name) => ['name' => (string) $name, 'actual' => round($g->sum('actual'), 2)])->filter(fn ($a) => $a['actual'] > 0)->sortByDesc('actual')->values(),
            'at_risk' => $expense->filter(fn ($r) => $r['revised'] > 0 && ($r['used_percent'] ?? 0) >= 90)->sortByDesc('used_percent')->take(10)
                ->map(fn ($r) => ['id' => $r['id'], 'label' => $r['label'], 'department' => $r['department'], 'revised' => $r['revised'], 'available' => $r['available'], 'used_percent' => $r['used_percent']])->values(),
            'pending' => [
                'department_budgets' => DepartmentBudget::where('budget_id', $budget->id)->where('status', 'pending')->when($departments !== null, fn ($q) => $q->whereIn('department_id', $departments))->count(),
                'transfers' => BudgetTransfer::where('budget_id', $budget->id)->where('status', 'pending')->when($departments !== null, fn ($q) => $q->whereHas('toItem', fn ($i) => $i->whereIn('department_id', $departments)))->count(),
                'releases' => BudgetRelease::where('budget_id', $budget->id)->where('status', 'pending')->count(),
            ],
        ]);
    }

    private function months(Budget $budget, $items): array
    {
        $start = $budget->fiscalYear ? Carbon::parse($budget->fiscalYear->start_date)->startOfMonth() : now()->startOfYear();
        $periods = $budget->periods();
        $perPeriod = 12 / $periods;
        $months = [];
        for ($m = 0; $m < 12; $m++) {
            $months[$m] = ['label' => $start->copy()->addMonths($m)->format('M'), 'planned' => 0.0, 'actual' => 0.0, 'revenue_planned' => 0.0, 'revenue_actual' => 0.0];
        }
        $items->loadMissing(['account', 'periods']);
        foreach ($items as $item) {
            $key = $item->account?->type === 'revenue' ? 'revenue_planned' : 'planned';
            $shares = $item->periods->pluck('amount', 'period');
            $total = (float) $shares->sum();
            for ($p = 1; $p <= $periods; $p++) {
                $amount = $total > 0 ? (float) ($shares[$p] ?? 0) / $total * $item->revised_amount : $item->revised_amount / $periods;
                for ($k = 0; $k < $perPeriod; $k++) {
                    $months[(int) (($p - 1) * $perPeriod + $k)][$key] += $amount / $perPeriod;
                }
            }
        }
        $types = $items->mapWithKeys(fn ($i) => [$i->id => $i->account?->type])->all();
        if ($types) {
            $lines = DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('e.company_id', app(CompanyContext::class)->id())->whereIn('e.status', ['posted', 'reversed'])->whereIn('l.budget_item_id', array_keys($types))
                ->select('e.date', 'l.budget_item_id', 'l.debit', 'l.credit')->get();
            foreach ($lines as $l) {
                $m = (int) $start->diffInMonths(Carbon::parse($l->date)->startOfMonth());
                if ($m < 0 || $m > 11) {
                    continue;
                }
                $revenue = ($types[$l->budget_item_id] ?? null) === 'revenue';
                $months[$m][$revenue ? 'revenue_actual' : 'actual'] += $revenue ? $l->credit - $l->debit : $l->debit - $l->credit;
            }
        }

        return array_map(fn ($m) => ['label' => $m['label'], 'planned' => round($m['planned'], 2), 'actual' => round($m['actual'], 2),
            'revenue_planned' => round($m['revenue_planned'], 2), 'revenue_actual' => round($m['revenue_actual'], 2)], array_values($months));
    }

    private function emptyTotals(): array
    {
        return ['revised' => 0.0, 'released' => 0.0, 'reserved' => 0.0, 'committed' => 0.0, 'actual' => 0.0, 'available' => 0.0, 'used_percent' => null, 'revenue_budget' => 0.0, 'revenue_actual' => 0.0, 'items' => 0];
    }
}
