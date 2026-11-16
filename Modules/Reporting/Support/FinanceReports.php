<?php

namespace Modules\Reporting\Support;

use App\Models\Department;
use App\Models\EmployeeLoan;
use App\Models\EmployeeMonthlyAdvance;
use App\Models\LoanHistory;
use App\Models\LoanProvider;
use App\Models\PayrollSheet;
use App\Models\PayrollSheetDetail;
use App\Models\User;
use App\Services\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Models\FundSource;
use Modules\Accounting\Models\JournalEntry;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Models\BudgetTransfer;
use Modules\Budgeting\Support\BudgetFigures;
use Modules\Core\Services\AccessService;
use Modules\Payments\Models\PaymentVoucher;
use Modules\Planning\Models\AnnualPlan;
use Modules\Receivables\Models\Receipt;
use Modules\Tasks\Models\Task;
use Modules\Tasks\Models\TaskActivity;

/** Finance and planning reports: accounting statements, payroll, loans, budget and plan performance. Same shape as HR reports. */
class FinanceReports
{
    public const REPORTS = [
        'income-statement' => ['title' => 'Statement of income and expenditure', 'group' => 'Accounting', 'module' => 'accounting', 'permissions' => ['accounting.view']],
        'financial-position' => ['title' => 'Statement of financial position', 'group' => 'Accounting', 'module' => 'accounting', 'permissions' => ['accounting.view']],
        'cash-book' => ['title' => 'Cash book', 'group' => 'Accounting', 'module' => 'accounting', 'permissions' => ['accounting.view']],
        'budget-comparison' => ['title' => 'Statement of comparison of budget and actual amounts', 'group' => 'Accounting', 'module' => 'budgeting', 'permissions' => ['accounting.view', 'budgeting.view', 'budgeting.manage']],
        'trial-balance' => ['title' => 'Trial balance', 'group' => 'Accounting', 'module' => 'accounting', 'permissions' => ['accounting.view']],
        'fund-statement' => ['title' => 'Income and expenditure by fund source', 'group' => 'Accounting', 'module' => 'accounting', 'permissions' => ['accounting.view']],
        'journal-register' => ['title' => 'Journal register', 'group' => 'Accounting', 'module' => 'accounting', 'permissions' => ['accounting.view']],
        'payments-register' => ['title' => 'Payments register', 'group' => 'Accounting', 'module' => 'payments', 'permissions' => ['payments.view', 'payments.vouchers', 'payments.pay', 'payments.manage']],
        'receipts-register' => ['title' => 'Receipts register', 'group' => 'Accounting', 'module' => 'receivables', 'permissions' => ['receivables.view', 'receivables.bill', 'receivables.receipts', 'receivables.manage']],
        'payroll-year' => ['title' => 'Employee earnings for the year', 'group' => 'Payroll', 'module' => 'payroll', 'permissions' => ['payroll.view']],
        'payroll-changes' => ['title' => 'Payroll changes from last month', 'group' => 'Payroll', 'module' => 'payroll', 'permissions' => ['payroll.view']],
        'loans-outstanding' => ['title' => 'Outstanding loans', 'group' => 'Loans', 'module' => 'loans', 'permissions' => ['loans.view', 'loans.manage']],
        'loans-by-provider' => ['title' => 'Loans by provider (remittance)', 'group' => 'Loans', 'module' => 'loans', 'permissions' => ['loans.view', 'loans.manage']],
        'loan-repayments' => ['title' => 'Loan repayments by month', 'group' => 'Loans', 'module' => 'loans', 'permissions' => ['loans.view', 'loans.manage']],
        'salary-advances' => ['title' => 'Salary advances', 'group' => 'Loans', 'module' => 'loans', 'permissions' => ['loans.view', 'loans.manage']],
        'budget-vs-actual' => ['title' => 'Budget against actual', 'group' => 'Budgeting', 'module' => 'budgeting', 'permissions' => ['budgeting.view', 'budgeting.manage']],
        'budget-quarterly' => ['title' => 'Budget against actual by period', 'group' => 'Budgeting', 'module' => 'budgeting', 'permissions' => ['budgeting.view', 'budgeting.manage']],
        'budget-commitments' => ['title' => 'Held and committed money', 'group' => 'Budgeting', 'module' => 'budgeting', 'permissions' => ['budgeting.view', 'budgeting.manage']],
        'budget-transfers' => ['title' => 'Budget transfers and supplementary budget', 'group' => 'Budgeting', 'module' => 'budgeting', 'permissions' => ['budgeting.view', 'budgeting.manage']],
        'plan-activities' => ['title' => 'Annual plan activities', 'group' => 'Planning', 'module' => 'planning', 'permissions' => ['planning.view', 'planning.manage']],
        'plan-performance' => ['title' => 'Plan performance by department', 'group' => 'Planning', 'module' => 'planning', 'permissions' => ['planning.view', 'planning.manage']],
        'objective-performance' => ['title' => 'Performance by strategic objective', 'group' => 'Planning', 'module' => 'planning', 'permissions' => ['planning.view', 'planning.manage']],
    ];

    private const LABELS = [
        'code' => 'Code', 'account' => 'Account', 'section' => 'Section', 'amount' => 'Amount', 'debit' => 'Debit', 'credit' => 'Credit', 'balance' => 'Balance',
        'date' => 'Date', 'number' => 'Number', 'description' => 'Description', 'type' => 'Type', 'status' => 'Status', 'fund' => 'Fund', 'revenue' => 'Income',
        'expense' => 'Expenditure', 'surplus' => 'Surplus / deficit', 'reference' => 'Reference', 'payee' => 'Payee', 'department' => 'Department', 'gross' => 'Gross',
        'withholding' => 'Tax withheld', 'net' => 'Net', 'bank' => 'Bank / cash', 'payer' => 'Received from', 'method' => 'Method', 'kind' => 'Kind',
        'employee_no' => 'No.', 'name' => 'Name', 'months' => 'Months', 'paye' => 'PAYE', 'nssf' => 'NSSF', 'nhif' => 'NHIF', 'heslb' => 'HESLB', 'loans' => 'Loans',
        'deductions' => 'Deductions', 'previous' => 'Last month', 'current' => 'This month', 'difference' => 'Difference', 'change' => 'Change',
        'provider' => 'Provider', 'loan_date' => 'Loan date', 'principal' => 'Principal', 'instalment' => 'Instalment', 'repaid' => 'Repaid', 'outstanding' => 'Outstanding',
        'end_date' => 'Ends', 'staff' => 'Staff', 'month' => 'Month', 'item' => 'Budget line', 'group' => 'Group', 'original' => 'Original', 'revised' => 'Revised budget',
        'released' => 'Released', 'held' => 'Held', 'committed' => 'Committed', 'actual' => 'Actual', 'available' => 'Available', 'used' => 'Used %',
        'from' => 'From', 'to' => 'To', 'reason' => 'Reason', 'requested_by' => 'Requested by', 'activity' => 'Activity', 'objective' => 'Objective', 'output' => 'Output',
        'target' => 'Target', 'kpi' => 'KPI', 'quarters' => 'Quarters', 'subs' => 'Sub-activities', 'budget' => 'Budget', 'spent' => 'Spent', 'tasks' => 'Tasks',
        'done' => 'Done', 'overdue' => 'Overdue', 'progress' => 'Progress %', 'activities' => 'Activities', 'plan_status' => 'Plan', 'period' => 'Period',
        'planned' => 'Planned', 'variance' => 'Variance', 'final' => 'Final budget', 'difference' => 'Difference', 'opening' => 'Opening', 'in' => 'Money in', 'out' => 'Money out', 'documents' => 'Documents',
    ];

    public function __construct(private readonly AccessService $access, private readonly BudgetFigures $figures) {}

    /** @return list<array{key: string, title: string, group: string, filters: list<array<string, mixed>>}> */
    public function catalog(User $user): array
    {
        $keys = array_values(array_filter(array_keys(self::REPORTS), fn ($k) => $this->available($k, $user)));

        $w = app(\Modules\Accounting\Services\AccountingPolicies::class)->wording();

        return array_map(fn ($k) => ['key' => $k, 'title' => match ($k) { 'income-statement' => $w['performance'], 'financial-position' => $w['position'], default => self::REPORTS[$k]['title'] }, 'group' => self::REPORTS[$k]['group'], 'filters' => $this->filters($k)], $keys);
    }

    public function available(string $key, User $user): bool
    {
        $r = self::REPORTS[$key] ?? null;

        return $r && $this->access->canUseModule($user, $r['module']) && collect($r['permissions'])->contains(fn ($p) => $this->access->can($user, $p));
    }

    public function run(string $key, array $input, User $user): array
    {
        abort_unless($this->available($key, $user), 404, 'This report is not available.');
        $values = collect($this->filters($key))->mapWithKeys(fn ($f) => [$f['key'] => ($input[$f['key']] ?? '') !== '' ? (string) $input[$f['key']] : ($f['default'] ?? null)])->all();
        try {
            [$subtitle, $columns, $rows] = $this->{'report'.str_replace('-', '', ucwords($key, '-'))}($values);
        } catch (MissingChoice $e) {
            [$subtitle, $columns, $rows] = [$e->getMessage(), ['description'], []];
        }

        $w = app(\Modules\Accounting\Services\AccountingPolicies::class)->wording();
        $title = match ($key) { 'income-statement' => $w['performance'], 'financial-position' => $w['position'], default => self::REPORTS[$key]['title'] };

        return ['key' => $key, 'title' => $title, 'subtitle' => $subtitle,
            'columns' => array_map(fn ($c) => ['key' => $c, 'label' => self::LABELS[$c] ?? ucfirst(str_replace('_', ' ', $c))], $columns), 'rows' => array_values($rows), 'total' => count($rows)];
    }

    /** @return list<array<string, mixed>> */
    private function filters(string $key): array
    {
        $today = CarbonImmutable::today();
        $year = FiscalYear::containing($today->toDateString());
        $from = ['key' => 'from', 'label' => 'From', 'type' => 'date', 'default' => $year?->start_date->toDateString() ?? $today->startOfYear()->toDateString()];
        $to = ['key' => 'to', 'label' => 'To', 'type' => 'date', 'default' => $today->toDateString()];
        $opt = fn ($rows, $label = 'name') => $rows->map(fn ($r) => ['value' => (string) $r->id, 'label' => is_callable($label) ? $label($r) : $r->{$label}])->values()->all();
        $departments = ['key' => 'department_id', 'label' => 'Department', 'type' => 'select', 'options' => $opt(Department::where('is_active', true)->orderBy('name')->get())];
        $budgets = Budget::latest('id')->get();
        $budget = ['key' => 'budget_id', 'label' => 'Budget', 'type' => 'select', 'default' => (string) ($budgets->firstWhere('status', 'approved')?->id ?? $budgets->first()?->id ?? ''), 'options' => $opt($budgets, 'title')];
        $plans = AnnualPlan::latest('year')->get();
        $plan = ['key' => 'plan_id', 'label' => 'Annual plan', 'type' => 'select', 'default' => (string) ($plans->firstWhere('year', $today->year)?->id ?? $plans->first()?->id ?? ''), 'options' => $opt($plans, 'title')];
        $years = PayrollSheet::query()->select('year')->distinct()->orderByDesc('year')->pluck('year')->map(fn ($y) => ['value' => (string) $y, 'label' => (string) $y])->values()->all();
        $payrollYear = ['key' => 'year', 'label' => 'Year', 'type' => 'select', 'default' => (string) $today->year, 'options' => $years ?: [['value' => (string) $today->year, 'label' => (string) $today->year]]];
        $sheets = $key === 'payroll-changes' ? PayrollSheet::get()->sortByDesc(fn ($s) => $s->year * 100 + (int) $s->month)->values() : collect();
        $providers = ['key' => 'provider_id', 'label' => 'Provider', 'type' => 'select', 'options' => $opt(LoanProvider::orderBy('name')->get())];

        return match ($key) {
            'income-statement', 'fund-statement' => [$from, $to, $departments],
            'financial-position' => [['key' => 'date', 'label' => 'As at', 'type' => 'date', 'default' => $today->toDateString()]],
            'cash-book' => [['key' => 'bank_id', 'label' => 'Bank or cash', 'type' => 'select', 'default' => (string) (CashBankAccount::where('is_active', true)->orderBy('name')->value('id') ?? ''),
                'options' => $opt(CashBankAccount::orderBy('name')->get())], $from, $to],
            'trial-balance' => [$from, $to],
            'journal-register' => [$from, $to, ['key' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => collect(JournalEntry::TYPES)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all()]],
            'payments-register' => [$from, $to, ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => collect(PaymentVoucher::STATUS_LABELS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all()]],
            'receipts-register' => [$from, $to, ['key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => collect(Receipt::KINDS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all()]],
            'payroll-year' => [$payrollYear, $departments],
            'payroll-changes' => [['key' => 'sheet_id', 'label' => 'Payroll', 'type' => 'select', 'default' => (string) ($sheets->first()?->id ?? ''), 'options' => $opt($sheets, 'name')]],
            'loans-outstanding' => [$providers, $departments],
            'loans-by-provider' => [],
            'loan-repayments' => [$payrollYear, $providers],
            'salary-advances' => [$payrollYear, ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => collect(EmployeeMonthlyAdvance::STATUS_LABELS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all()]],
            'budget-vs-actual' => [$budget, ['key' => 'group', 'label' => 'Group by', 'type' => 'select', 'default' => 'department', 'options' => [
                ['value' => 'department', 'label' => 'Department'], ['value' => 'account', 'label' => 'Account'], ['value' => 'activity', 'label' => 'Activity'],
                ['value' => 'fund', 'label' => 'Fund source'], ['value' => 'item', 'label' => 'Budget line']]], $departments],
            'budget-quarterly', 'budget-commitments' => [$budget, $departments],
            'budget-comparison' => [$budget],
            'budget-transfers' => [$budget],
            'plan-activities' => [$plan, $departments],
            'plan-performance', 'objective-performance' => [$plan],
            default => [],
        };
    }

    private function lines(?string $from, ?string $to): Builder
    {
        return DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.company_id', app(CompanyContext::class)->id())->whereIn('e.status', ['posted', 'reversed'])
            ->when($from, fn ($q) => $q->whereDate('e.date', '>=', $from))->when($to, fn ($q) => $q->whereDate('e.date', '<=', $to));
    }

    private function d(?string $date): string
    {
        return $date ? CarbonImmutable::parse($date)->format('j M Y') : '—';
    }

    private function reportIncomeStatement(array $v): array
    {
        $sums = $this->lines($v['from'], $v['to'])->when($v['department_id'], fn ($q, $d) => $q->where('l.department_id', $d))
            ->groupBy('l.account_id')->selectRaw('l.account_id, SUM(l.debit) as d, SUM(l.credit) as c')->get()->keyBy('account_id');
        $rows = [];
        $totals = [];
        $w = app(\Modules\Accounting\Services\AccountingPolicies::class)->wording($v['to']);
        foreach (['revenue' => $w['revenue'], 'expense' => $w['expense']] as $type => $label) {
            $rows[] = ['code' => '', 'account' => strtoupper($label), 'amount' => null];
            $total = 0;
            foreach (Account::where('type', $type)->where('is_postable', true)->orderBy('code')->get() as $a) {
                $s = $sums[$a->id] ?? null;
                $amount = $s ? $a->balance((float) $s->d, (float) $s->c) : 0;
                if (round($amount, 2) != 0) {
                    $rows[] = ['code' => $a->code, 'account' => $a->name, 'amount' => $amount];
                    $total += $amount;
                }
            }
            $totals[$type] = round($total, 2);
            $rows[] = ['code' => '', 'account' => "Total {$label}", 'amount' => $totals[$type]];
        }
        $rows[] = ['code' => '', 'account' => $w['result'], 'amount' => round($totals['revenue'] - $totals['expense'], 2)];
        $dept = $v['department_id'] ? ' · '.Department::find($v['department_id'])?->name : '';

        return ["{$this->d($v['from'])} to {$this->d($v['to'])}{$dept}", ['code', 'account', 'amount'], $rows];
    }

    private function reportFinancialPosition(array $v): array
    {
        $sums = $this->lines(null, $v['date'])->groupBy('l.account_id')->selectRaw('l.account_id, SUM(l.debit) as d, SUM(l.credit) as c')->get()->keyBy('account_id');
        $accounts = Account::where('is_postable', true)->orderBy('code')->get();
        $bal = fn (Account $a) => isset($sums[$a->id]) ? $a->balance((float) $sums[$a->id]->d, (float) $sums[$a->id]->c) : 0.0;
        $surplus = round($accounts->whereIn('type', ['revenue'])->sum($bal) - $accounts->whereIn('type', ['expense'])->sum($bal), 2);
        $rows = [];
        $totals = [];
        $w = app(\Modules\Accounting\Services\AccountingPolicies::class)->wording($v['date']);
        foreach (['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => $w['equity']] as $type => $label) {
            $rows[] = ['code' => '', 'account' => strtoupper($label), 'amount' => null];
            $total = 0;
            foreach ($accounts->where('type', $type) as $a) {
                $amount = $bal($a);
                if (round($amount, 2) != 0) {
                    $rows[] = ['code' => $a->code, 'account' => $a->name, 'amount' => $amount];
                    $total += $amount;
                }
            }
            if ($type === 'equity') {
                $rows[] = ['code' => '', 'account' => $w['accumulated'], 'amount' => $surplus];
                $total += $surplus;
            }
            $totals[$type] = round($total, 2);
            $rows[] = ['code' => '', 'account' => "Total {$label}", 'amount' => $totals[$type]];
        }
        $rows[] = ['code' => '', 'account' => $w['total_claims'], 'amount' => round($totals['liability'] + $totals['equity'], 2)];

        return ['As at '.$this->d($v['date']), ['code', 'account', 'amount'], $rows];
    }

    private function reportCashBook(array $v): array
    {
        $bank = CashBankAccount::find((int) $v['bank_id']);
        if (! $bank || ! $bank->account_id) {
            throw new MissingChoice('Choose a bank or cash account.');
        }
        $before = $v['from'] ? CarbonImmutable::parse($v['from'])->subDay()->toDateString() : null;
        $open = $before ? $this->lines(null, $before)->where('l.account_id', $bank->account_id)->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) as b')->value('b') : 0;
        $balance = round((float) $open, 2);
        $rows = [['date' => $this->d($v['from']), 'number' => '', 'description' => 'Opening balance', 'in' => null, 'out' => null, 'balance' => $balance]];
        $moves = $this->lines($v['from'], $v['to'])->where('l.account_id', $bank->account_id)->orderBy('e.date')->orderBy('e.id')
            ->select('e.date', 'e.reference_no', DB::raw('COALESCE(l.description, e.description) as description'), 'l.debit', 'l.credit')->get();
        foreach ($moves as $m) {
            $balance = round($balance + $m->debit - $m->credit, 2);
            $rows[] = ['date' => $this->d($m->date), 'number' => $m->reference_no, 'description' => $m->description, 'in' => $m->debit ? (float) $m->debit : null, 'out' => $m->credit ? (float) $m->credit : null, 'balance' => $balance];
        }
        $rows[] = ['date' => $this->d($v['to']), 'number' => '', 'description' => 'Closing balance', 'in' => round($moves->sum('debit'), 2), 'out' => round($moves->sum('credit'), 2), 'balance' => $balance];

        return ["{$bank->name} · {$this->d($v['from'])} to {$this->d($v['to'])}", ['date', 'number', 'description', 'in', 'out', 'balance'], $rows];
    }

    private function reportTrialBalance(array $v): array
    {
        $sums = $this->lines($v['from'], $v['to'])->groupBy('l.account_id')->selectRaw('l.account_id, SUM(l.debit) as d, SUM(l.credit) as c')->get()->keyBy('account_id');
        $rows = Account::whereIn('id', $sums->keys())->orderBy('code')->get()->map(function (Account $a) use ($sums) {
            $net = round((float) $sums[$a->id]->d - (float) $sums[$a->id]->c, 2);

            return ['code' => $a->code, 'account' => $a->name, 'type' => Account::TYPES[$a->type] ?? $a->type, 'debit' => $net > 0 ? $net : null, 'credit' => $net < 0 ? -$net : null];
        })->filter(fn ($r) => $r['debit'] || $r['credit'])->values();
        $rows->push(['code' => '', 'account' => 'Total', 'type' => '', 'debit' => round($rows->sum('debit'), 2), 'credit' => round($rows->sum('credit'), 2)]);

        return ["{$this->d($v['from'])} to {$this->d($v['to'])}", ['code', 'account', 'type', 'debit', 'credit'], $rows->all()];
    }

    private function reportFundStatement(array $v): array
    {
        $rows = $this->lines($v['from'], $v['to'])->join('accounts as a', 'a.id', '=', 'l.account_id')->whereIn('a.type', ['revenue', 'expense'])
            ->when($v['department_id'], fn ($q, $d) => $q->where('l.department_id', $d))
            ->groupBy('l.fund_source_id', 'a.type')->selectRaw('l.fund_source_id, a.type, SUM(l.debit) as d, SUM(l.credit) as c')->get()->groupBy('fund_source_id');
        $funds = FundSource::get()->keyBy('id');
        $out = $rows->map(function ($g, $fund) use ($funds) {
            $rev = $g->firstWhere('type', 'revenue');
            $exp = $g->firstWhere('type', 'expense');
            $revenue = $rev ? round((float) $rev->c - (float) $rev->d, 2) : 0.0;
            $expense = $exp ? round((float) $exp->d - (float) $exp->c, 2) : 0.0;
            $f = $funds[$fund] ?? null;

            return ['fund' => $f ? "{$f->code} {$f->name}" : 'No fund source', 'revenue' => $revenue, 'expense' => $expense, 'surplus' => round($revenue - $expense, 2)];
        })->sortBy('fund')->values();
        $out->push(['fund' => 'Total', 'revenue' => round($out->sum('revenue'), 2), 'expense' => round($out->sum('expense'), 2), 'surplus' => round($out->sum('surplus'), 2)]);

        return ["{$this->d($v['from'])} to {$this->d($v['to'])} · for donors and restricted funds", ['fund', 'revenue', 'expense', 'surplus'], $out->all()];
    }

    private function reportJournalRegister(array $v): array
    {
        $rows = JournalEntry::with('creator')->whereIn('status', ['posted', 'reversed'])->when($v['from'], fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($v['to'], fn ($q, $d) => $q->whereDate('date', '<=', $d))->when($v['type'], fn ($q, $t) => $q->where('entry_type', $t))->orderBy('date')->orderBy('id')->limit(5000)->get()
            ->map(fn ($e) => ['date' => $e->date->format('j M Y'), 'number' => $e->reference_no, 'type' => JournalEntry::TYPES[$e->entry_type] ?? $e->entry_type, 'description' => $e->description,
                'amount' => (float) $e->total, 'status' => JournalEntry::STATUS_LABELS[$e->status] ?? $e->status]);

        return ["{$this->d($v['from'])} to {$this->d($v['to'])}", ['date', 'number', 'type', 'description', 'amount', 'status'], $rows->all()];
    }

    private function reportPaymentsRegister(array $v): array
    {
        $rows = PaymentVoucher::with(['request.department', 'request.payees', 'bank'])->when($v['from'], fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($v['to'], fn ($q, $d) => $q->whereDate('date', '<=', $d))->when($v['status'], fn ($q, $s) => $q->where('status', $s))->orderBy('date')->orderBy('id')->get()
            ->map(fn ($p) => ['date' => $p->date->format('j M Y'), 'number' => $p->number, 'reference' => $p->request?->number, 'payee' => $p->request?->payees->pluck('name')->join(', '),
                'department' => $p->request?->department?->name, 'description' => $p->description, 'bank' => $p->bank?->name, 'gross' => (float) $p->gross, 'withholding' => (float) $p->withholding,
                'net' => (float) $p->net, 'status' => PaymentVoucher::STATUS_LABELS[$p->status] ?? $p->status]);
        if ($rows->count() > 1) {
            $rows->push(['date' => 'Total', 'gross' => round($rows->sum('gross'), 2), 'withholding' => round($rows->sum('withholding'), 2), 'net' => round($rows->sum('net'), 2)]);
        }

        return ["{$this->d($v['from'])} to {$this->d($v['to'])}", ['date', 'number', 'reference', 'payee', 'department', 'description', 'bank', 'gross', 'withholding', 'net', 'status'], $rows->all()];
    }

    private function reportReceiptsRegister(array $v): array
    {
        $rows = Receipt::with(['bank', 'allocations.invoice'])->when($v['from'], fn ($q, $d) => $q->whereDate('date', '>=', $d))->when($v['to'], fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($v['kind'], fn ($q, $k) => $q->where('kind', $k))->orderBy('date')->orderBy('id')->get()
            ->map(fn ($r) => ['date' => $r->date->format('j M Y'), 'number' => $r->number, 'payer' => $r->payer_name, 'kind' => Receipt::KINDS[$r->kind] ?? $r->kind,
                'documents' => $r->allocations->pluck('invoice.number')->join(', '), 'bank' => $r->bank?->name, 'method' => Receipt::METHODS[$r->method] ?? $r->method,
                'reference' => $r->reference, 'amount' => $r->status === 'voided' ? 0.0 : (float) $r->amount, 'status' => Receipt::STATUS_LABELS[$r->status] ?? $r->status]);
        if ($rows->count() > 1) {
            $rows->push(['date' => 'Total', 'amount' => round($rows->sum('amount'), 2)]);
        }

        return ["{$this->d($v['from'])} to {$this->d($v['to'])} · voided receipts count as zero", ['date', 'number', 'payer', 'kind', 'documents', 'bank', 'method', 'reference', 'amount', 'status'], $rows->all()];
    }

    private function reportPayrollYear(array $v): array
    {
        $sheets = PayrollSheet::where('year', $v['year'])->where('status', 'approved')->pluck('id');
        $rows = PayrollSheetDetail::with('employee.department')->whereIn('payroll_sheet_id', $sheets)
            ->when($v['department_id'], fn ($q, $d) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $d)))->get()
            ->groupBy('employee_id')->map(function (Collection $g) {
                $e = $g->first()->employee;
                $s = fn ($c) => round($g->sum($c), 2);

                return ['employee_no' => $e?->employee_no, 'name' => $g->first()->full_name, 'department' => $e?->department?->name, 'months' => $g->count(), 'gross' => $s('gross_salary'),
                    'paye' => $s('paye'), 'nssf' => $s('nssf'), 'nhif' => $s('nhif'), 'heslb' => $s('heslb'), 'loans' => $s('total_loan'), 'deductions' => $s('total_deduction'), 'net' => $s('net_salary')];
            })->sortBy('name')->values();
        if ($rows->count() > 1) {
            $total = ['employee_no' => '', 'name' => 'Total', 'department' => '', 'months' => null];
            foreach (['gross', 'paye', 'nssf', 'nhif', 'heslb', 'loans', 'deductions', 'net'] as $k) {
                $total[$k] = round($rows->sum($k), 2);
            }
            $rows->push($total);
        }

        return ["Approved payrolls of {$v['year']} · employee contributions", ['employee_no', 'name', 'department', 'months', 'gross', 'paye', 'nssf', 'nhif', 'heslb', 'loans', 'deductions', 'net'], $rows->all()];
    }

    private function reportPayrollChanges(array $v): array
    {
        $sheet = PayrollSheet::find((int) $v['sheet_id']);
        if (! $sheet) {
            throw new MissingChoice('No payroll yet.');
        }
        $prev = PayrollSheet::get()->filter(fn ($s) => $s->year * 100 + (int) $s->month < $sheet->year * 100 + (int) $sheet->month)->sortByDesc(fn ($s) => $s->year * 100 + (int) $s->month)->first();
        $now = PayrollSheetDetail::with('employee')->where('payroll_sheet_id', $sheet->id)->get()->keyBy('employee_id');
        $before = $prev ? PayrollSheetDetail::with('employee')->where('payroll_sheet_id', $prev->id)->get()->keyBy('employee_id') : collect();
        $rows = $now->keys()->merge($before->keys())->unique()->map(function ($id) use ($now, $before) {
            $a = $before[$id] ?? null;
            $b = $now[$id] ?? null;
            $diff = round(($b?->gross_salary ?? 0) - ($a?->gross_salary ?? 0), 2);

            return ['employee_no' => ($b ?? $a)->employee?->employee_no, 'name' => ($b ?? $a)->full_name, 'previous' => $a ? (float) $a->gross_salary : null, 'current' => $b ? (float) $b->gross_salary : null,
                'difference' => $diff, 'change' => ! $a ? 'New on payroll' : (! $b ? 'Left payroll' : ($diff > 0 ? 'Increase' : 'Decrease'))];
        })->filter(fn ($r) => $r['difference'] != 0 || $r['previous'] === null || $r['current'] === null)->sortBy('name')->values();

        return [$sheet->name.' against '.($prev?->name ?? 'nothing (first payroll)').' · gross pay', ['employee_no', 'name', 'previous', 'current', 'difference', 'change'], $rows->all()];
    }

    private function reportLoansOutstanding(array $v): array
    {
        $rows = EmployeeLoan::with(['employee.department', 'loanProvider'])->whereIn('status', ['enabled', 'paused'])->where('remain', '>', 0)
            ->when($v['provider_id'], fn ($q, $p) => $q->where('loan_provider_id', $p))
            ->when($v['department_id'], fn ($q, $d) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $d)))->get()
            ->map(fn ($l) => ['employee_no' => $l->employee?->employee_no, 'name' => trim("{$l->employee?->fname} {$l->employee?->lname}"), 'department' => $l->employee?->department?->name,
                'provider' => $l->loanProvider?->name, 'loan_date' => $l->date_of_loan?->format('j M Y'), 'principal' => $l->amount, 'instalment' => $l->instalment, 'repaid' => $l->returned,
                'outstanding' => $l->remain, 'end_date' => $l->end_date?->format('j M Y'), 'status' => EmployeeLoan::STATUS_LABELS[$l->status] ?? $l->status])->sortBy('name')->values();
        if ($rows->count() > 1) {
            $rows->push(['name' => 'Total', 'principal' => round($rows->sum('principal'), 2), 'instalment' => round($rows->sum('instalment'), 2), 'repaid' => round($rows->sum('repaid'), 2), 'outstanding' => round($rows->sum('outstanding'), 2)]);
        }

        return ['Loans still being repaid', ['employee_no', 'name', 'department', 'provider', 'loan_date', 'principal', 'instalment', 'repaid', 'outstanding', 'end_date', 'status'], $rows->all()];
    }

    private function reportLoansByProvider(array $v): array
    {
        $rows = EmployeeLoan::with('loanProvider')->whereIn('status', ['enabled', 'paused'])->get()->groupBy('loan_provider_id')
            ->map(fn ($g) => ['provider' => $g->first()->loanProvider?->name ?? 'No provider', 'staff' => $g->pluck('employee_id')->unique()->count(), 'principal' => round($g->sum('amount'), 2),
                'instalment' => round($g->sum('instalment'), 2), 'repaid' => round($g->sum('returned'), 2), 'outstanding' => round($g->sum('remain'), 2)])->sortBy('provider')->values();
        if ($rows->count() > 1) {
            $rows->push(['provider' => 'Total', 'staff' => $rows->sum('staff')] + collect(['principal', 'instalment', 'repaid', 'outstanding'])->mapWithKeys(fn ($k) => [$k => round($rows->sum($k), 2)])->all());
        }

        return ['Active loans · instalment is what is remitted each month', ['provider', 'staff', 'principal', 'instalment', 'repaid', 'outstanding'], $rows->all()];
    }

    private function reportLoanRepayments(array $v): array
    {
        $sheets = PayrollSheet::where('year', $v['year'])->get()->keyBy('id');
        $provider = $v['provider_id'] ? LoanProvider::find($v['provider_id']) : null;
        $rows = LoanHistory::whereIn('payroll_sheet_id', $sheets->keys())->when($provider, fn ($q) => $q->where('loan_provider', $provider->name))
            ->whereHas('employee')->get()->groupBy(fn ($h) => $h->payroll_sheet_id.'|'.$h->loan_provider)
            ->map(fn ($g) => ['month' => $sheets[$g->first()->payroll_sheet_id]?->name, 'order' => (int) $sheets[$g->first()->payroll_sheet_id]?->month, 'provider' => $g->first()->loan_provider,
                'staff' => $g->pluck('employee_id')->unique()->count(), 'repaid' => round($g->sum('repaid_amount'), 2), 'outstanding' => round($g->sum('outstanding_loan'), 2)])
            ->sortBy([['order', 'asc'], ['provider', 'asc']])->map(fn ($r) => collect($r)->except('order')->all())->values();
        if ($rows->count() > 1) {
            $rows->push(['month' => 'Total', 'provider' => '', 'staff' => null, 'repaid' => round($rows->sum('repaid'), 2)]);
        }

        return ["Deducted through payroll in {$v['year']}", ['month', 'provider', 'staff', 'repaid', 'outstanding'], $rows->all()];
    }

    private function reportSalaryAdvances(array $v): array
    {
        $rows = EmployeeMonthlyAdvance::with('employee.department')->where('year', $v['year'])->when($v['status'], fn ($q, $s) => $q->where('status', $s))->get()
            ->sortBy(fn ($a) => [(int) $a->month, $a->employee?->fname])->map(fn ($a) => ['date' => $this->d($a->date_advanced), 'month' => $a->month ? CarbonImmutable::create(2000, (int) $a->month, 1)->format('F') : '',
                'employee_no' => $a->employee?->employee_no, 'name' => trim("{$a->employee?->fname} {$a->employee?->lname}"), 'department' => $a->employee?->department?->name,
                'amount' => (float) $a->amount, 'status' => EmployeeMonthlyAdvance::STATUS_LABELS[$a->status] ?? $a->status])->values();
        if ($rows->count() > 1) {
            $rows->push(['date' => 'Total', 'amount' => round($rows->sum('amount'), 2)]);
        }

        return ["Salary advances of {$v['year']}", ['date', 'month', 'employee_no', 'name', 'department', 'amount', 'status'], $rows->all()];
    }

    private function budget(array $v): Budget
    {
        $budget = Budget::with('fiscalYear')->find((int) ($v['budget_id'] ?? 0));
        if (! $budget) {
            throw new MissingChoice('No budget yet.');
        }

        return $budget;
    }

    private function budgetRows(Budget $budget, ?string $department): Collection
    {
        return $this->figures->items(BudgetItem::where('budget_id', $budget->id)->when($department, fn ($q, $d) => $q->where('department_id', $d))->get());
    }

    private function reportBudgetVsActual(array $v): array
    {
        $budget = $this->budget($v);
        $key = match ($v['group']) {
            'account' => fn ($r) => $r['account'] ? $r['account']['code'].' '.$r['account']['name'] : 'No account',
            'activity' => fn ($r) => $r['activity'] ? trim(($r['activity']['code'] ?? '').' '.$r['activity']['title']) : 'No activity',
            'fund' => fn ($r) => $r['fund_source']['code'] ?? 'No fund source',
            'item' => fn ($r) => $r['label'].' · '.$r['department'],
            default => fn ($r) => $r['department'] ?? 'No department',
        };
        $rows = $this->budgetRows($budget, $v['department_id'])->groupBy($key)->map(function ($g, $name) {
            $s = fn ($c) => round($g->sum($c), 2);
            $revised = $s('revised');

            return ['group' => (string) $name, 'original' => $s('original'), 'revised' => $revised, 'released' => $s('released'), 'held' => $s('reserved'), 'committed' => $s('committed'),
                'actual' => $s('actual'), 'available' => $s('available'), 'used' => $revised > 0 ? round(($s('actual') + $s('reserved') + $s('committed')) / $revised * 100, 1) : null];
        })->sortBy('group')->values();
        if ($rows->count() > 1) {
            $t = ['group' => 'Total'];
            foreach (['original', 'revised', 'released', 'held', 'committed', 'actual', 'available'] as $k) {
                $t[$k] = round($rows->sum($k), 2);
            }
            $t['used'] = $t['revised'] > 0 ? round(($t['actual'] + $t['held'] + $t['committed']) / $t['revised'] * 100, 1) : null;
            $rows->push($t);
        }

        return ["{$budget->title} · income and expenditure lines · used = actual + held + committed", ['group', 'original', 'revised', 'released', 'held', 'committed', 'actual', 'available', 'used'], $rows->all()];
    }

    /** IPSAS 24 style: original and final budget against actual on the same basis, by account. */
    private function reportBudgetComparison(array $v): array
    {
        $budget = $this->budget($v);
        $w = app(\Modules\Accounting\Services\AccountingPolicies::class)->wording();
        $rows = collect();
        foreach (['revenue' => $w['revenue'], 'expense' => $w['expense']] as $type => $heading) {
            $items = $this->budgetRows($budget, null)->filter(fn ($r) => ($r['account']['type'] ?? 'expense') === $type);
            if ($items->isEmpty()) {
                continue;
            }
            $rows->push(['account' => strtoupper($heading), 'original' => null, 'final' => null, 'actual' => null, 'difference' => null]);
            foreach ($items->groupBy(fn ($r) => $r['account'] ? $r['account']['code'].' '.$r['account']['name'] : 'Other')->sortKeys() as $name => $g) {
                $rows->push(['account' => (string) $name, 'original' => round($g->sum('original'), 2), 'final' => round($g->sum('revised'), 2), 'actual' => round($g->sum('actual'), 2),
                    'difference' => round($g->sum('revised') - $g->sum('actual'), 2)]);
            }
            $rows->push(['account' => "Total {$heading}", 'original' => round($items->sum('original'), 2), 'final' => round($items->sum('revised'), 2), 'actual' => round($items->sum('actual'), 2),
                'difference' => round($items->sum('revised') - $items->sum('actual'), 2)]);
        }

        return ["{$budget->title} · difference = final budget − actual", ['account', 'original', 'final', 'actual', 'difference'], $rows->all()];
    }

    private function reportBudgetQuarterly(array $v): array
    {
        $budget = $this->budget($v);
        $labels = $budget->periodLabels();
        $periods = $budget->periods();
        $items = BudgetItem::with(['periods', 'account', 'department'])->where('budget_id', $budget->id)->when($v['department_id'], fn ($q, $d) => $q->where('department_id', $d))->get();
        $start = CarbonImmutable::parse($budget->fiscalYear?->start_date ?? now()->startOfYear())->startOfMonth();
        $actual = [];
        $lines = $this->lines(null, null)->whereIn('l.budget_item_id', $items->pluck('id'))->select('e.date', 'l.budget_item_id', 'l.debit', 'l.credit')->get();
        $types = $items->mapWithKeys(fn ($i) => [$i->id => $i->account?->type])->all();
        foreach ($lines as $l) {
            $m = (int) $start->diffInMonths(CarbonImmutable::parse($l->date)->startOfMonth());
            if ($m < 0 || $m > 11) {
                continue;
            }
            $p = intdiv($m, 12 / $periods) + 1;
            $actual[$l->budget_item_id][$p] = ($actual[$l->budget_item_id][$p] ?? 0) + (($types[$l->budget_item_id] ?? '') === 'revenue' ? $l->credit - $l->debit : $l->debit - $l->credit);
        }
        $rows = collect();
        foreach ($items->groupBy(fn ($i) => $i->department?->name ?? 'No department')->sortKeys() as $dept => $group) {
            foreach (range(1, $periods) as $p) {
                $planned = $group->sum(function ($i) use ($p, $periods) {
                    $total = (float) $i->periods->sum('amount');

                    return $total > 0 ? (float) ($i->periods->firstWhere('period', $p)?->amount ?? 0) / $total * $i->revised_amount : $i->revised_amount / $periods;
                });
                $spent = $group->sum(fn ($i) => $actual[$i->id][$p] ?? 0);
                $rows->push(['department' => $dept, 'period' => $labels[$p - 1] ?? "P{$p}", 'planned' => round($planned, 2), 'actual' => round($spent, 2), 'variance' => round($planned - $spent, 2),
                    'used' => $planned > 0 ? round($spent / $planned * 100, 1) : null]);
            }
        }

        return ["{$budget->title} · planned share of the revised budget against actual per period", ['department', 'period', 'planned', 'actual', 'variance', 'used'], $rows->all()];
    }

    private function reportBudgetCommitments(array $v): array
    {
        $budget = $this->budget($v);
        $rows = $this->budgetRows($budget, $v['department_id'])->filter(fn ($r) => $r['reserved'] > 0 || $r['committed'] > 0)
            ->map(fn ($r) => ['item' => $r['label'], 'department' => $r['department'], 'revised' => $r['revised'], 'held' => $r['reserved'], 'committed' => $r['committed'], 'actual' => $r['actual'], 'available' => $r['available']])
            ->sortBy('department')->values();
        if ($rows->count() > 1) {
            $rows->push(['item' => 'Total', 'department' => ''] + collect(['revised', 'held', 'committed', 'actual', 'available'])->mapWithKeys(fn ($k) => [$k => round($rows->sum($k), 2)])->all());
        }

        return ["{$budget->title} · money held by requests waiting and committed by approved requests not yet spent", ['item', 'department', 'revised', 'held', 'committed', 'actual', 'available'], $rows->all()];
    }

    private function reportBudgetTransfers(array $v): array
    {
        $budget = $this->budget($v);
        $rows = BudgetTransfer::with(['fromItem.account', 'fromItem.department', 'fromItem.subActivity', 'fromItem.activity', 'toItem.account', 'toItem.department', 'toItem.subActivity', 'toItem.activity', 'requester'])
            ->where('budget_id', $budget->id)->orderBy('id')->get()
            ->map(fn ($t) => ['date' => $t->created_at->format('j M Y'), 'type' => BudgetTransfer::KINDS[$t->kind] ?? $t->kind, 'from' => $t->fromItem ? $t->fromItem->label().' ('.$t->fromItem->department?->name.')' : '—',
                'to' => $t->toItem ? $t->toItem->label().' ('.$t->toItem->department?->name.')' : '—', 'amount' => (float) $t->amount, 'reason' => $t->reason, 'requested_by' => $t->requester?->display_name,
                'status' => BudgetTransfer::STATUS_LABELS[$t->status] ?? $t->status]);

        return [$budget->title, ['date', 'type', 'from', 'to', 'amount', 'reason', 'requested_by', 'status'], $rows->all()];
    }

    private function plan(array $v): AnnualPlan
    {
        $plan = AnnualPlan::find((int) ($v['plan_id'] ?? 0));
        if (! $plan) {
            throw new MissingChoice('No annual plan yet.');
        }

        return $plan;
    }

    /** @return array{0: Collection, 1: Collection, 2: array<int, array{budget: float, spent: float}>} activities, tasks by activity, money by activity */
    private function planData(AnnualPlan $plan, ?string $department = null): array
    {
        $activities = TaskActivity::with(['objective', 'department'])->withCount('subActivities')->where('annual_plan_id', $plan->id)
            ->when($department, fn ($q, $d) => $q->where('department_id', $d))->orderBy('department_id')->orderBy('code')->get();
        $tasks = Task::whereIn('task_activity_id', $activities->pluck('id'))->where('status', '!=', 'cancelled')->get(['id', 'task_activity_id', 'status', 'progress', 'due_date'])->groupBy('task_activity_id');
        $items = BudgetItem::whereIn('task_activity_id', $activities->pluck('id'))->whereHas('account', fn ($q) => $q->where('type', 'expense'))->get();
        $figures = $this->figures->items($items);
        $money = [];
        foreach ($figures->groupBy(fn ($r) => $items->firstWhere('id', $r['id'])->task_activity_id) as $id => $g) {
            $money[$id] = ['budget' => round($g->sum('revised'), 2), 'spent' => round($g->sum('actual'), 2)];
        }

        return [$activities, $tasks, $money];
    }

    private function progress(Collection $tasks): ?float
    {
        return $tasks->isEmpty() ? null : round($tasks->avg(fn ($t) => $t->status === 'done' ? 100 : (int) $t->progress), 1);
    }

    private function reportPlanActivities(array $v): array
    {
        $plan = $this->plan($v);
        [$activities, $tasks, $money] = $this->planData($plan, $v['department_id']);
        $rows = $activities->map(fn ($a) => ['department' => $a->department?->name, 'code' => $a->code, 'activity' => $a->title, 'objective' => $a->objective ? trim(($a->objective->code ?? '').' '.$a->objective->title) : '',
            'output' => $a->output, 'target' => $a->target, 'kpi' => $a->kpi, 'quarters' => $a->quarters ? 'Q'.str_replace(',', ', Q', $a->quarters) : '', 'subs' => (int) $a->sub_activities_count,
            'budget' => $money[$a->id]['budget'] ?? 0.0, 'spent' => $money[$a->id]['spent'] ?? 0.0, 'tasks' => ($tasks[$a->id] ?? collect())->count(), 'progress' => $this->progress($tasks[$a->id] ?? collect())]);

        return [$plan->title, ['department', 'code', 'activity', 'objective', 'output', 'target', 'kpi', 'quarters', 'subs', 'budget', 'spent', 'tasks', 'progress'], $rows->all()];
    }

    private function reportPlanPerformance(array $v): array
    {
        $plan = $this->plan($v);
        [$activities, $tasks, $money] = $this->planData($plan);
        $statuses = $plan->departmentPlans()->get()->keyBy('department_id');
        $rows = $activities->groupBy('department_id')->map(function ($g, $dept) use ($tasks, $money, $statuses) {
            $t = $g->flatMap(fn ($a) => $tasks[$a->id] ?? collect());
            $budget = round($g->sum(fn ($a) => $money[$a->id]['budget'] ?? 0), 2);
            $spent = round($g->sum(fn ($a) => $money[$a->id]['spent'] ?? 0), 2);
            $status = $statuses[$dept]->status ?? null;

            return ['department' => $g->first()->department?->name, 'plan_status' => $status ? (\Modules\Planning\Models\DepartmentPlan::STATUS_LABELS[$status] ?? $status) : '', 'activities' => $g->count(),
                'tasks' => $t->count(), 'done' => $t->where('status', 'done')->count(), 'overdue' => $t->filter(fn ($x) => $x->status !== 'done' && $x->due_date && $x->due_date->lt(today()))->count(),
                'progress' => $this->progress($t), 'budget' => $budget, 'spent' => $spent, 'used' => $budget > 0 ? round($spent / $budget * 100, 1) : null];
        })->sortBy('department')->values();

        return ["{$plan->title} · progress from tasks, money from budget lines of the activities", ['department', 'plan_status', 'activities', 'tasks', 'done', 'overdue', 'progress', 'budget', 'spent', 'used'], $rows->all()];
    }

    private function reportObjectivePerformance(array $v): array
    {
        $plan = $this->plan($v);
        [$activities, $tasks, $money] = $this->planData($plan);
        $rows = $activities->groupBy(fn ($a) => $a->strategic_objective_id ?? 0)->map(function ($g) use ($tasks, $money) {
            $o = $g->first()->objective;
            $t = $g->flatMap(fn ($a) => $tasks[$a->id] ?? collect());
            $budget = round($g->sum(fn ($a) => $money[$a->id]['budget'] ?? 0), 2);
            $spent = round($g->sum(fn ($a) => $money[$a->id]['spent'] ?? 0), 2);

            return ['objective' => $o ? trim(($o->code ?? '').' '.$o->title) : 'No objective', 'activities' => $g->count(), 'tasks' => $t->count(), 'done' => $t->where('status', 'done')->count(),
                'progress' => $this->progress($t), 'budget' => $budget, 'spent' => $spent, 'used' => $budget > 0 ? round($spent / $budget * 100, 1) : null];
        })->sortBy('objective')->values();

        return [$plan->title, ['objective', 'activities', 'tasks', 'done', 'progress', 'budget', 'spent', 'used'], $rows->all()];
    }
}
