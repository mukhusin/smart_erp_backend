<?php

namespace Modules\Reporting\Support;

use App\Models\Department;
use App\Services\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Accounting\Models\PayrollPostingSetting;

/** Expense ratios from the ledger: expenses against income, staff costs, cost structure and (when departments are classed) the programme expense ratio. */
class ExpenseRatios
{
    public const UNCLASSED = 'unclassed';

    /**
     * @return array{revenue: float, expense: float, surplus: float, operating_ratio: ?float, staff_cost: float, staff_ratio: ?float,
     *   accounts: list<array{code: string, name: string, amount: float, share: ?float, of_revenue: ?float}>,
     *   classes: ?list<array{key: string, label: string, amount: float, share: ?float}>, programme_ratio: ?float, programme_target: ?float}
     */
    public function figures(string $from, string $to, ?int $fundSourceId = null): array
    {
        $rows = $this->lines($from, $to, $fundSourceId)->groupBy('l.account_id', 'l.department_id')
            ->selectRaw('l.account_id, l.department_id, SUM(l.debit) as d, SUM(l.credit) as c')->get();
        $accounts = Account::whereIn('type', ['revenue', 'expense'])->get()->keyBy('id');
        $revenue = $expense = 0.0;
        $byAccount = [];
        $byDepartment = [];
        foreach ($rows as $r) {
            $a = $accounts[$r->account_id] ?? null;
            if (! $a) {
                continue;
            }
            $amount = $a->balance((float) $r->d, (float) $r->c);
            if ($a->type === 'revenue') {
                $revenue += $amount;

                continue;
            }
            $expense += $amount;
            $byAccount[$a->id] = ($byAccount[$a->id] ?? 0) + $amount;
            $byDepartment[(int) $r->department_id] = ($byDepartment[(int) $r->department_id] ?? 0) + $amount;
        }
        $pct = fn (float $part, float $whole) => abs($whole) > 0.004 ? round($part / $whole * 100, 1) : null;
        $posting = PayrollPostingSetting::current();
        $staffIds = array_filter([$posting->salary_account_id, $posting->employer_account_id]);
        $staff = round(array_sum(array_intersect_key($byAccount, array_flip($staffIds))), 2);
        arsort($byAccount);

        $settings = AccountingSetting::current();
        $classes = null;
        $programme = null;
        if ($settings->expense_classes) {
            $classOf = $this->classes();
            $sums = array_fill_keys([...array_keys(Department::EXPENSE_CLASSES), self::UNCLASSED], 0.0);
            foreach ($byDepartment as $deptId => $amount) {
                $sums[$classOf[$deptId] ?? self::UNCLASSED] += $amount;
            }
            $labels = Department::EXPENSE_CLASSES + [self::UNCLASSED => 'Not classed'];
            $classes = collect($sums)->map(fn ($v, $k) => ['key' => $k, 'label' => $labels[$k], 'amount' => round($v, 2), 'share' => $pct($v, $expense)])
                ->filter(fn ($c) => $c['key'] !== self::UNCLASSED || round($c['amount'], 2) != 0)->values()->all();
            $programme = $pct($sums['programme'], $expense);
        }

        return [
            'revenue' => round($revenue, 2), 'expense' => round($expense, 2), 'surplus' => round($revenue - $expense, 2),
            'operating_ratio' => $pct($expense, $revenue), 'staff_cost' => $staff, 'staff_ratio' => $pct($staff, $expense),
            'accounts' => collect($byAccount)->filter(fn ($v) => round($v, 2) != 0)->map(fn ($v, $id) => ['code' => (string) $accounts[$id]->code, 'name' => $accounts[$id]->name,
                'amount' => round($v, 2), 'share' => $pct($v, $expense), 'of_revenue' => $pct($v, $revenue)])->values()->all(),
            'classes' => $classes, 'programme_ratio' => $programme, 'programme_target' => $settings->expense_classes ? $settings->programme_target : null,
        ];
    }

    /** @return list<array{month: string, revenue: float, expense: float, operating_ratio: ?float, programme_ratio: ?float}> */
    public function trend(CarbonImmutable $start, int $months): array
    {
        $out = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $start->addMonths($i);
            $f = $this->figures($m->startOfMonth()->toDateString(), $m->endOfMonth()->toDateString());
            $out[] = ['month' => $m->format('M Y'), 'revenue' => $f['revenue'], 'expense' => $f['expense'], 'operating_ratio' => $f['operating_ratio'], 'programme_ratio' => $f['programme_ratio']];
        }

        return $out;
    }

    /** @return array<int, string> department id => class; sections without one follow their department */
    private function classes(): array
    {
        $all = Department::withTrashed()->get(['id', 'parent_id', 'expense_class'])->keyBy('id');

        return $all->map(fn ($d) => $d->expense_class ?: ($d->parent_id ? $all[$d->parent_id]?->expense_class : null))->filter()->all();
    }

    private function lines(string $from, string $to, ?int $fundSourceId)
    {
        return DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.company_id', app(CompanyContext::class)->id())->whereIn('e.status', ['posted', 'reversed'])->where('e.entry_type', '!=', 'closing')
            ->whereDate('e.date', '>=', $from)->whereDate('e.date', '<=', $to)->when($fundSourceId, fn ($q, $f) => $q->where('l.fund_source_id', $f));
    }
}
