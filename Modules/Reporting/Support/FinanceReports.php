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
        'equity-changes' => ['title' => 'Statement of changes in equity', 'group' => 'Accounting', 'module' => 'accounting', 'permissions' => ['accounting.view']],
        'cash-flow' => ['title' => 'Cash flow statement', 'group' => 'Accounting', 'module' => 'accounting', 'permissions' => ['accounting.view']],
        'income-monthly' => ['title' => 'Income and expenditure by month', 'group' => 'Accounting', 'module' => 'accounting', 'permissions' => ['accounting.view']],
        'ledger-check' => ['title' => 'Ledger integrity check', 'group' => 'Accounting', 'module' => 'accounting', 'permissions' => ['accounting.view']],
        'payables-ageing' => ['title' => 'Aged payables (suppliers)', 'group' => 'Accounting', 'module' => 'payments', 'permissions' => ['payments.view', 'payments.vouchers', 'payments.pay', 'payments.manage', 'accounting.view']],
        'supplier-statement' => ['title' => 'Supplier statement', 'group' => 'Accounting', 'module' => 'payments', 'permissions' => ['payments.view', 'payments.vouchers', 'payments.pay', 'payments.manage', 'accounting.view']],
        'supplier-list' => ['title' => 'Suppliers with balances', 'group' => 'Accounting', 'module' => 'payments', 'permissions' => ['payments.view', 'payments.vouchers', 'payments.pay', 'payments.manage', 'accounting.view']],
        'unpaid-requests' => ['title' => 'Approved payment requests not yet vouchered', 'group' => 'Accounting', 'module' => 'payments', 'permissions' => ['payments.view', 'payments.vouchers', 'payments.pay', 'payments.manage', 'accounting.view']],
        'unpaid-vouchers' => ['title' => 'Approved vouchers not yet paid', 'group' => 'Accounting', 'module' => 'payments', 'permissions' => ['payments.view', 'payments.vouchers', 'payments.pay', 'payments.manage', 'accounting.view']],
        'collections-summary' => ['title' => 'Collections summary', 'group' => 'Accounting', 'module' => 'receivables', 'permissions' => ['receivables.view', 'receivables.receipts', 'receivables.manage', 'accounting.view']],
        'journal-register' => ['title' => 'Journal register', 'group' => 'Accounting', 'module' => 'accounting', 'permissions' => ['accounting.view']],
        'payments-register' => ['title' => 'Payments register', 'group' => 'Accounting', 'module' => 'payments', 'permissions' => ['payments.view', 'payments.vouchers', 'payments.pay', 'payments.manage']],
        'receipts-register' => ['title' => 'Receipts register', 'group' => 'Accounting', 'module' => 'receivables', 'permissions' => ['receivables.view', 'receivables.bill', 'receivables.receipts', 'receivables.manage']],
        'stock-balance' => ['title' => 'Store balances (quantity and value)', 'group' => 'Inventory', 'module' => 'inventory', 'permissions' => ['inventory.view', 'inventory.manage', 'inventory.stores', 'accounting.view']],
        'stock-movements' => ['title' => 'Receipts and issues', 'group' => 'Inventory', 'module' => 'inventory', 'permissions' => ['inventory.view', 'inventory.manage', 'inventory.stores', 'accounting.view']],
        'stock-consumption' => ['title' => 'Consumption by department', 'group' => 'Inventory', 'module' => 'inventory', 'permissions' => ['inventory.view', 'inventory.manage', 'inventory.stores', 'accounting.view']],
        'stock-expiry' => ['title' => 'Expiring and expired stock', 'group' => 'Inventory', 'module' => 'inventory', 'permissions' => ['inventory.view', 'inventory.manage', 'inventory.stores', 'accounting.view']],
        'stock-reorder' => ['title' => 'Items at or below reorder level', 'group' => 'Inventory', 'module' => 'inventory', 'permissions' => ['inventory.view', 'inventory.manage', 'inventory.stores', 'accounting.view']],
        'stock-slow' => ['title' => 'Slow-moving and dead stock', 'group' => 'Inventory', 'module' => 'inventory', 'permissions' => ['inventory.view', 'inventory.manage', 'inventory.stores', 'accounting.view']],
        'stock-transit' => ['title' => 'Goods in transit and shortages', 'group' => 'Inventory', 'module' => 'inventory', 'permissions' => ['inventory.view', 'inventory.manage', 'inventory.stores', 'accounting.view']],
        'stock-count-variance' => ['title' => 'Stock count variances', 'group' => 'Inventory', 'module' => 'inventory', 'permissions' => ['inventory.view', 'inventory.manage', 'inventory.stores', 'accounting.view']],
        'asset-register' => ['title' => 'Fixed asset register', 'group' => 'Assets', 'module' => 'assets', 'permissions' => ['assets.view', 'assets.manage', 'accounting.view']],
        'asset-movements' => ['title' => 'Property, plant and equipment movements (note)', 'group' => 'Assets', 'module' => 'assets', 'permissions' => ['assets.view', 'assets.manage', 'accounting.view']],
        'depreciation-schedule' => ['title' => 'Depreciation schedule', 'group' => 'Assets', 'module' => 'assets', 'permissions' => ['assets.view', 'assets.manage', 'accounting.view']],
        'assets-by-holder' => ['title' => 'Assets by holder and location', 'group' => 'Assets', 'module' => 'assets', 'permissions' => ['assets.view', 'assets.manage', 'accounting.view']],
        'assets-due-disposal' => ['title' => 'Assets due for disposal or fully depreciated', 'group' => 'Assets', 'module' => 'assets', 'permissions' => ['assets.view', 'assets.manage', 'accounting.view']],
        'asset-disposals' => ['title' => 'Disposal register', 'group' => 'Assets', 'module' => 'assets', 'permissions' => ['assets.view', 'assets.manage', 'accounting.view']],
        'asset-transfers' => ['title' => 'Transfers register', 'group' => 'Assets', 'module' => 'assets', 'permissions' => ['assets.view', 'assets.manage', 'accounting.view']],
        'tender-register' => ['title' => 'Tender register', 'group' => 'Procurement', 'module' => 'procurement', 'permissions' => ['procurement.view', 'procurement.orders', 'procurement.manage', 'accounting.view']],
        'bid-opening' => ['title' => 'Bid opening record', 'group' => 'Procurement', 'module' => 'procurement', 'permissions' => ['procurement.view', 'procurement.orders', 'procurement.manage', 'accounting.view']],
        'evaluation-summary' => ['title' => 'Evaluation summary', 'group' => 'Procurement', 'module' => 'procurement', 'permissions' => ['procurement.view', 'procurement.orders', 'procurement.manage', 'accounting.view']],
        'tender-awards' => ['title' => 'Awards against the estimate', 'group' => 'Procurement', 'module' => 'procurement', 'permissions' => ['procurement.view', 'procurement.orders', 'procurement.manage', 'accounting.view']],
        'contract-register' => ['title' => 'Contract register', 'group' => 'Procurement', 'module' => 'procurement', 'permissions' => ['procurement.view', 'procurement.orders', 'procurement.manage', 'accounting.view']],
        'contracts-expiring' => ['title' => 'Expiring contracts and securities', 'group' => 'Procurement', 'module' => 'procurement', 'permissions' => ['procurement.view', 'procurement.orders', 'procurement.manage', 'accounting.view']],
        'orders-outstanding' => ['title' => 'Purchase orders outstanding', 'group' => 'Procurement', 'module' => 'procurement', 'permissions' => ['procurement.view', 'procurement.orders', 'procurement.manage', 'accounting.view']],
        'supplier-performance' => ['title' => 'Supplier performance', 'group' => 'Procurement', 'module' => 'procurement', 'permissions' => ['procurement.view', 'procurement.orders', 'procurement.manage', 'accounting.view']],
        'spend-by-method' => ['title' => 'Spend by procurement method', 'group' => 'Procurement', 'module' => 'procurement', 'permissions' => ['procurement.view', 'procurement.orders', 'procurement.manage', 'accounting.view']],
        'special-group-share' => ['title' => 'Special-group share', 'group' => 'Procurement', 'module' => 'procurement', 'permissions' => ['procurement.view', 'procurement.orders', 'procurement.manage', 'accounting.view']],
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
        'planned' => 'Planned', 'variance' => 'Variance', 'store' => 'Store', 'item' => 'Item', 'unit' => 'Unit', 'quantity' => 'Quantity', 'value' => 'Value', 'avg_cost' => 'Average cost',
        'batch' => 'Batch', 'expiry' => 'Expiry', 'days' => 'Days left', 'reorder' => 'Reorder level', 'last_issue' => 'Last issued', 'counted' => 'Counted', 'system' => 'Ledger', 'final' => 'Final budget', 'difference' => 'Difference', 'opening' => 'Opening', 'in' => 'Money in', 'out' => 'Money out', 'documents' => 'Documents',
        'movement' => 'Movements', 'result' => 'Result', 'closing' => 'Closing', 'supplier' => 'Supplier', 'tin' => 'TIN', 'phone' => 'Phone', 'bills' => 'Open bills',
        'not_due' => 'Not yet due', 'd30' => '1–30 days', 'd60' => '31–60 days', 'd90' => '61–90 days', 'over90' => 'Over 90 days', 'total' => 'Total', 'billed' => 'Billed',
        'paid' => 'Paid', 'age' => 'Days waiting', 'tender' => 'Tender', 'bidders' => 'Bids', 'closing' => 'Closing', 'estimate' => 'Estimate', 'awarded' => 'Awarded',
        'saving' => 'Below estimate', 'lot' => 'Lot', 'read_out' => 'Read-out price', 'documents' => 'Documents', 'responsive' => 'Responsive', 'technical' => 'Technical',
        'financial' => 'Financial', 'combined' => 'Combined', 'rank' => 'Rank', 'contract' => 'Contract', 'varied' => 'Variations', 'certified' => 'Certified / ordered', 'retained' => 'Retained',
        'end_date' => 'Ends', 'security' => 'Security expires', 'ordered' => 'Ordered', 'received' => 'Received', 'due' => 'Still due', 'deliveries' => 'Deliveries', 'on_time' => 'On time %',
        'rejected' => 'Rejected %', 'complaints' => 'Complaints', 'share' => 'Share %', 'group_name' => 'Special group', 'category' => 'Category', 'holder' => 'Held by', 'location' => 'Location', 'acquired' => 'Acquired', 'cost' => 'Cost',
        'depreciation' => 'Depreciation', 'accumulated' => 'Accumulated depreciation', 'book_value' => 'Book value', 'additions' => 'Additions', 'revaluation' => 'Revaluation',
        'disposals' => 'Disposals', 'condition' => 'Condition', 'since' => 'Since', 'asset' => 'Asset', 'proceeds' => 'Proceeds', 'gain_loss' => 'Gain / loss',
        'destination' => 'To', 'return_date' => 'Return by', 'serial' => 'Serial', 'opening_cost' => 'Opening cost', 'closing_cost' => 'Closing cost', 'due' => 'Due', 'count' => 'Count', 'issue' => 'Issue', 'line' => 'Line',
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

        return $this->build($key, $input);
    }

    /** Runs a report without the access check, for snapshots taken by the system (year-end close). */
    public function build(string $key, array $input): array
    {
        $values = collect($this->filters($key))->mapWithKeys(fn ($f) => [$f['key'] => ($input[$f['key']] ?? '') !== '' ? (string) $input[$f['key']] : ($f['default'] ?? null)])->all();
        try {
            [$subtitle, $columns, $rows, $labels] = array_pad($this->{'report'.str_replace('-', '', ucwords($key, '-'))}($values), 4, []);
        } catch (MissingChoice $e) {
            [$subtitle, $columns, $rows, $labels] = [$e->getMessage(), ['description'], [], []];
        }

        $w = app(\Modules\Accounting\Services\AccountingPolicies::class)->wording();
        $title = match ($key) { 'income-statement' => $w['performance'], 'financial-position' => $w['position'], default => self::REPORTS[$key]['title'] };

        return ['key' => $key, 'title' => $title, 'subtitle' => $subtitle,
            'columns' => array_map(fn ($c) => ['key' => $c, 'label' => $labels[$c] ?? self::LABELS[$c] ?? ucfirst(str_replace('_', ' ', $c))], $columns), 'rows' => array_values($rows), 'total' => count($rows)];
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
        $storesF = ['key' => 'store_id', 'label' => 'Store', 'type' => 'select', 'options' => class_exists(\Modules\Inventory\Models\Store::class) ? $opt(\Modules\Inventory\Models\Store::where('kind', '!=', 'transit')->orderBy('name')->get()) : []];
        $assetCats = ['key' => 'category_id', 'label' => 'Category', 'type' => 'select', 'options' => class_exists(\Modules\Inventory\Models\ItemCategory::class)
            ? $opt(\Modules\Inventory\Models\ItemCategory::whereIn('type', ['asset', 'intangible', 'wip'])->orderBy('name')->get()) : []];
        $providers = ['key' => 'provider_id', 'label' => 'Provider', 'type' => 'select', 'options' => $opt(LoanProvider::orderBy('name')->get())];

        return match ($key) {
            'income-statement', 'fund-statement' => [$from, $to, $departments],
            'financial-position' => [['key' => 'date', 'label' => 'As at', 'type' => 'date', 'default' => $today->toDateString()]],
            'cash-book' => [['key' => 'bank_id', 'label' => 'Bank or cash', 'type' => 'select', 'default' => (string) (CashBankAccount::where('is_active', true)->orderBy('name')->value('id') ?? ''),
                'options' => $opt(CashBankAccount::orderBy('name')->get())], $from, $to],
            'trial-balance' => [$from, $to],
            'equity-changes', 'cash-flow', 'ledger-check' => [$from, $to],
            'tender-register', 'tender-awards', 'spend-by-method', 'special-group-share', 'supplier-performance' => [$from, $to],
            'bid-opening', 'evaluation-summary' => [['key' => 'tender_id', 'label' => 'Tender', 'type' => 'select', 'options' => class_exists(\Modules\Procurement\Models\Tender::class)
                ? $opt(\Modules\Procurement\Models\Tender::whereNotNull('opened_at')->orderByDesc('id')->get(), fn ($t) => "{$t->number} {$t->title}") : []]],
            'contract-register' => [['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => collect(\Modules\Procurement\Models\Contract::STATUS_LABELS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all()]],
            'contracts-expiring' => [['key' => 'days', 'label' => 'Within days', 'type' => 'select', 'default' => '30', 'options' => [['value' => '30', 'label' => '30 days'], ['value' => '60', 'label' => '60 days'], ['value' => '90', 'label' => '90 days']]]],
            'orders-outstanding' => [],
            'asset-register' => [['key' => 'date', 'label' => 'As at', 'type' => 'date', 'default' => $today->toDateString()], $assetCats, $departments],
            'asset-movements' => [$from, $to],
            'depreciation-schedule' => [$from, $to, $assetCats],
            'assets-by-holder' => [$departments, $assetCats],
            'assets-due-disposal' => [$assetCats],
            'asset-disposals', 'asset-transfers' => [$from, $to],
            'income-monthly' => [$from, $to, $departments],
            'payables-ageing', 'supplier-list', 'unpaid-requests', 'unpaid-vouchers' => [],
            'supplier-statement' => [['key' => 'supplier_id', 'label' => 'Supplier', 'type' => 'select', 'options' => class_exists(\Modules\Procurement\Models\Supplier::class)
                ? $opt(\Modules\Procurement\Models\Supplier::orderBy('name')->get()) : []], $from, $to],
            'collections-summary' => [$from, $to, ['key' => 'group', 'label' => 'Group by', 'type' => 'select', 'default' => 'month', 'options' => [
                ['value' => 'month', 'label' => 'Month'], ['value' => 'method', 'label' => 'Payment method'], ['value' => 'bank', 'label' => 'Bank or cash account'],
                ['value' => 'cashier', 'label' => 'Received by'], ['value' => 'kind', 'label' => 'Kind of receipt']]]],
            'journal-register' => [$from, $to, ['key' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => collect(JournalEntry::TYPES)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all()]],
            'payments-register' => [$from, $to, ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => collect(PaymentVoucher::STATUS_LABELS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all()]],
            'receipts-register' => [$from, $to, ['key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => collect(Receipt::KINDS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all()]],
            'stock-balance', 'stock-reorder', 'stock-expiry', 'stock-slow' => [$storesF],
            'stock-movements' => [$storesF, $from, $to, ['key' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => collect(\Modules\Inventory\Models\StockMovement::TYPES)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all()]],
            'stock-consumption' => [$from, $to, $storesF],
            'stock-transit' => [],
            'stock-count-variance' => [$from, $to, $storesF],
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
        $sums = $this->lines($v['from'], $v['to'])->where('e.entry_type', '!=', 'closing')->when($v['department_id'], fn ($q, $d) => $q->where('l.department_id', $d))
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
            if ($type === 'equity' && round($surplus, 2) != 0) {
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
        $sums = $this->lines($v['from'], $v['to'])->where('e.entry_type', '!=', 'closing')->groupBy('l.account_id')->selectRaw('l.account_id, SUM(l.debit) as d, SUM(l.credit) as c')->get()->keyBy('account_id');
        $rows = Account::whereIn('id', $sums->keys())->orderBy('code')->get()->map(function (Account $a) use ($sums) {
            $net = round((float) $sums[$a->id]->d - (float) $sums[$a->id]->c, 2);

            return ['code' => $a->code, 'account' => $a->name, 'type' => Account::TYPES[$a->type] ?? $a->type, 'debit' => $net > 0 ? $net : null, 'credit' => $net < 0 ? -$net : null];
        })->filter(fn ($r) => $r['debit'] || $r['credit'])->values();
        $rows->push(['code' => '', 'account' => 'Total', 'type' => '', 'debit' => round($rows->sum('debit'), 2), 'credit' => round($rows->sum('credit'), 2)]);

        return ["{$this->d($v['from'])} to {$this->d($v['to'])}", ['code', 'account', 'type', 'debit', 'credit'], $rows->all()];
    }

    private function reportFundStatement(array $v): array
    {
        $rows = $this->lines($v['from'], $v['to'])->where('e.entry_type', '!=', 'closing')->join('accounts as a', 'a.id', '=', 'l.account_id')->whereIn('a.type', ['revenue', 'expense'])
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

    private function balances(?string $from, ?string $to, bool $withClosing = true): Collection
    {
        return $this->lines($from, $to)->when(! $withClosing, fn ($q) => $q->where('e.entry_type', '!=', 'closing'))
            ->groupBy('l.account_id')->selectRaw('l.account_id, SUM(l.debit) as d, SUM(l.credit) as c')->get()->keyBy('account_id');
    }

    private function reportEquityChanges(array $v): array
    {
        $before = CarbonImmutable::parse($v['from'])->subDay()->toDateString();
        $accounts = Account::where('is_postable', true)->orderBy('code')->get();
        $open = $this->balances(null, $before);
        $moves = $this->balances($v['from'], $v['to'], false);
        $closing = $this->lines($v['from'], $v['to'])->where('e.entry_type', 'closing')->groupBy('l.account_id')->selectRaw('l.account_id, SUM(l.debit) as d, SUM(l.credit) as c')->get()->keyBy('account_id');
        $bal = fn (Collection $set, Account $a) => isset($set[$a->id]) ? $a->balance((float) $set[$a->id]->d, (float) $set[$a->id]->c) : 0.0;
        $pnl = fn (Collection $set) => round($accounts->where('type', 'revenue')->sum(fn ($a) => $bal($set, $a)) - $accounts->where('type', 'expense')->sum(fn ($a) => $bal($set, $a)), 2);
        $rows = [];
        foreach ($accounts->where('type', 'equity') as $a) {
            $o = $bal($open, $a);
            $m = $bal($moves, $a);
            $r = $bal($closing, $a);
            if (round($o, 2) != 0 || round($m, 2) != 0 || round($r, 2) != 0) {
                $rows[] = ['account' => $a->label(), 'opening' => $o, 'movement' => $m, 'result' => $r, 'closing' => round($o + $m + $r, 2)];
            }
        }
        $w = app(\Modules\Accounting\Services\AccountingPolicies::class)->wording($v['to']);
        $unclosedOpen = $pnl($open);
        $periodResult = $pnl($moves);
        $closedInPeriod = round(collect($rows)->sum('result'), 2);
        $rows[] = ['account' => $w['accumulated'].' (not yet closed)', 'opening' => $unclosedOpen, 'movement' => 0.0, 'result' => round($periodResult - $closedInPeriod, 2), 'closing' => round($unclosedOpen + $periodResult - $closedInPeriod, 2)];
        $rows[] = ['account' => 'Total', 'opening' => round(collect($rows)->sum('opening'), 2), 'movement' => round(collect($rows)->sum('movement'), 2), 'result' => round(collect($rows)->sum('result'), 2), 'closing' => round(collect($rows)->sum('closing'), 2)];

        return ["{$this->d($v['from'])} to {$this->d($v['to'])} · {$w['result']} for the period ".number_format($periodResult, 2), ['account', 'opening', 'movement', 'result', 'closing'], $rows];
    }

    private function reportCashFlow(array $v): array
    {
        $cash = CashBankAccount::whereNotNull('account_id')->pluck('account_id')->unique()->all();
        if (! $cash) {
            throw new MissingChoice('Add your bank and cash accounts in Accounting → Bank and cash first.');
        }
        $before = CarbonImmutable::parse($v['from'])->subDay()->toDateString();
        $opening = round((float) $this->lines(null, $before)->whereIn('l.account_id', $cash)->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) as b')->value('b'), 2);
        $entries = $this->lines($v['from'], $v['to'])->whereExists(fn ($q) => $q->selectRaw('1')->from('journal_entry_lines as x')->whereColumn('x.journal_entry_id', 'e.id')->whereIn('x.account_id', $cash))
            ->select('l.journal_entry_id', 'l.account_id', 'l.debit', 'l.credit')->get()->groupBy('journal_entry_id');
        $accounts = Account::get()->keyBy('id');
        $by = [];
        foreach ($entries as $lines) {
            $delta = round($lines->whereIn('account_id', $cash)->sum(fn ($l) => $l->debit - $l->credit), 2);
            $others = $lines->reject(fn ($l) => in_array($l->account_id, $cash));
            $weight = round($others->sum(fn ($l) => $l->credit - $l->debit), 2);
            if ($delta == 0 || $weight == 0) {
                continue;
            }
            foreach ($others as $l) {
                $share = round($delta * ($l->credit - $l->debit) / $weight, 2);
                $by[$l->account_id] = round(($by[$l->account_id] ?? 0) + $share, 2);
            }
        }
        $rows = [['section' => 'Cash and bank at '.$this->d($v['from']), 'account' => '', 'amount' => $opening]];
        $net = 0.0;
        foreach (Account::CASH_FLOW as $key => $label) {
            $lines = collect($by)->filter(fn ($amt, $id) => isset($accounts[$id]) && $accounts[$id]->cashFlow() === $key && round($amt, 2) != 0)
                ->sortBy(fn ($amt, $id) => $accounts[$id]->code);
            $rows[] = ['section' => strtoupper($label), 'account' => '', 'amount' => null];
            foreach ($lines as $id => $amt) {
                $rows[] = ['section' => '', 'account' => $accounts[$id]->label(), 'amount' => $amt];
            }
            $total = round($lines->sum(), 2);
            $net += $total;
            $rows[] = ['section' => "Net cash from {$label}", 'account' => '', 'amount' => $total];
        }
        $rows[] = ['section' => 'Net change in cash', 'account' => '', 'amount' => round($net, 2)];
        $rows[] = ['section' => 'Cash and bank at '.$this->d($v['to']), 'account' => '', 'amount' => round($opening + $net, 2)];

        return ["{$this->d($v['from'])} to {$this->d($v['to'])} · direct method; set investing and financing accounts in the chart of accounts", ['section', 'account', 'amount'], $rows];
    }

    private function reportIncomeMonthly(array $v): array
    {
        $start = CarbonImmutable::parse($v['from'])->startOfMonth();
        $end = CarbonImmutable::parse($v['to']);
        $months = [];
        for ($m = $start; $m->lte($end) && count($months) < 12; $m = $m->addMonth()) {
            $months[$m->format('Y-m')] = $m->format('M y');
        }
        $sums = $this->lines($v['from'], $v['to'])->where('e.entry_type', '!=', 'closing')->when($v['department_id'], fn ($q, $d) => $q->where('l.department_id', $d))
            ->groupBy('l.account_id', 'e.date')->selectRaw('l.account_id, e.date, SUM(l.debit) as d, SUM(l.credit) as c')->get()
            ->groupBy(fn ($r) => $r->account_id.'|'.substr((string) $r->date, 0, 7));
        $w = app(\Modules\Accounting\Services\AccountingPolicies::class)->wording($v['to']);
        $keys = array_map(fn ($k) => 'm'.str_replace('-', '', $k), array_keys($months));
        $rows = [];
        $totals = [];
        foreach (['revenue' => $w['revenue'], 'expense' => $w['expense']] as $type => $label) {
            $rows[] = ['account' => strtoupper($label)];
            $sum = array_fill_keys($keys, 0.0) + ['total' => 0.0];
            foreach (Account::where('type', $type)->where('is_postable', true)->orderBy('code')->get() as $a) {
                $row = ['account' => $a->label()];
                $any = false;
                foreach (array_keys($months) as $i => $ym) {
                    $g = $sums[$a->id.'|'.$ym] ?? collect();
                    $amt = $a->balance((float) $g->sum('d'), (float) $g->sum('c'));
                    $row[$keys[$i]] = $amt;
                    $sum[$keys[$i]] = round($sum[$keys[$i]] + $amt, 2);
                    $any = $any || round($amt, 2) != 0;
                }
                $row['total'] = round(array_sum(array_intersect_key($row, array_flip($keys))), 2);
                if ($any) {
                    $sum['total'] = round($sum['total'] + $row['total'], 2);
                    $rows[] = $row;
                }
            }
            $totals[$type] = $sum;
            $rows[] = ['account' => "Total {$label}"] + $sum;
        }
        $rows[] = ['account' => $w['result']] + array_map(fn ($k) => round($totals['revenue'][$k] - $totals['expense'][$k], 2), array_combine([...$keys, 'total'], [...$keys, 'total']));
        $dept = $v['department_id'] ? ' · '.Department::find($v['department_id'])?->name : '';
        $columns = array_merge(['account'], $keys, ['total']);
        $labels = array_combine($keys, array_values($months));

        return ["{$this->d($v['from'])} to {$this->d($v['to'])}{$dept}", $columns, $rows, $labels];
    }

    private function reportLedgerCheck(array $v): array
    {
        $rows = [];
        $unbalanced = $this->lines($v['from'], $v['to'])->groupBy('e.id', 'e.reference_no', 'e.date')->selectRaw('e.id, e.reference_no, e.date, SUM(l.debit) as d, SUM(l.credit) as c')
            ->havingRaw('ROUND(SUM(l.debit) - SUM(l.credit), 2) <> 0')->get();
        foreach ($unbalanced as $e) {
            $rows[] = ['date' => $this->d($e->date), 'number' => $e->reference_no, 'issue' => 'Debits and credits differ', 'debit' => (float) $e->d, 'credit' => (float) $e->c, 'difference' => round($e->d - $e->c, 2)];
        }
        $headings = $this->lines($v['from'], $v['to'])->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.is_postable', false)->select('e.reference_no', 'e.date', 'a.code', 'l.debit', 'l.credit')->get();
        foreach ($headings as $h) {
            $rows[] = ['date' => $this->d($h->date), 'number' => $h->reference_no, 'issue' => "Posted to heading account {$h->code}", 'debit' => (float) $h->debit, 'credit' => (float) $h->credit, 'difference' => null];
        }
        $noPeriod = JournalEntry::whereIn('status', ['posted', 'reversed'])->whereNull('fiscal_period_id')->whereBetween('date', [$v['from'], $v['to']])->get();
        foreach ($noPeriod as $e) {
            $rows[] = ['date' => $e->date->format('j M Y'), 'number' => $e->reference_no, 'issue' => 'Not in a fiscal period', 'debit' => (float) $e->total, 'credit' => (float) $e->total, 'difference' => null];
        }
        $totals = $this->lines($v['from'], $v['to'])->selectRaw('COALESCE(SUM(l.debit), 0) as d, COALESCE(SUM(l.credit), 0) as c')->first();
        $subtitle = $rows ? count($rows).' issue(s) found' : 'No issues: every posted journal balances';

        return ["{$this->d($v['from'])} to {$this->d($v['to'])} · {$subtitle} · ledger debits ".number_format((float) $totals->d, 2).' / credits '.number_format((float) $totals->c, 2),
            ['date', 'number', 'issue', 'debit', 'credit', 'difference'], $rows];
    }

    private function openBills(): Collection
    {
        return \Modules\Payments\Models\PaymentRequest::with(['supplier', 'vouchers.listItems'])->whereNotNull('supplier_id')->whereIn('status', ['approved', 'vouchered'])->get()
            ->map(function ($r) {
                $paid = $r->vouchers->flatMap->listItems->where('status', 'paid')->sum('amount');
                $r->setAttribute('balance', round($r->total - $paid, 2));

                return $r;
            })->filter(fn ($r) => $r->balance > 0);
    }

    private function bucket(?CarbonImmutable $due): string
    {
        $days = $due ? (int) $due->diffInDays(CarbonImmutable::today(), false) : 0;

        return match (true) { $days <= 0 => 'not_due', $days <= 30 => 'd30', $days <= 60 => 'd60', $days <= 90 => 'd90', default => 'over90' };
    }

    private function reportPayablesAgeing(array $v): array
    {
        $buckets = ['not_due', 'd30', 'd60', 'd90', 'over90'];
        $rows = $this->openBills()->groupBy('supplier_id')->map(function ($bills) use ($buckets) {
            $row = ['supplier' => $bills->first()->supplier?->name] + array_fill_keys($buckets, 0.0);
            foreach ($bills as $b) {
                $due = $b->needed_by ? CarbonImmutable::parse($b->needed_by) : ($b->decided_at ? CarbonImmutable::parse($b->decided_at) : null);
                $row[$this->bucket($due)] = round($row[$this->bucket($due)] + $b->balance, 2);
            }
            $row['total'] = round(array_sum(array_intersect_key($row, array_flip($buckets))), 2);

            return $row;
        })->sortByDesc('total')->values();
        $total = ['supplier' => 'Total'];
        foreach ([...$buckets, 'total'] as $k) {
            $total[$k] = round($rows->sum($k), 2);
        }
        $rows->push($total);

        return ['As at '.$this->d(CarbonImmutable::today()->toDateString()).' · approved supplier bills not yet paid, by due date', ['supplier', ...$buckets, 'total'], $rows->all()];
    }

    private function reportSupplierStatement(array $v): array
    {
        $supplier = $v['supplier_id'] ? \Modules\Procurement\Models\Supplier::find((int) $v['supplier_id']) : null;
        if (! $supplier) {
            throw new MissingChoice('Choose a supplier.');
        }
        $bills = \Modules\Payments\Models\PaymentRequest::with('vouchers.listItems.list')->where('supplier_id', $supplier->id)->whereIn('status', ['approved', 'vouchered', 'paid'])->get();
        $events = collect();
        foreach ($bills as $b) {
            $events->push(['date' => CarbonImmutable::parse($b->decided_at ?? $b->created_at)->toDateString(), 'number' => $b->number, 'description' => $b->title, 'billed' => (float) $b->total, 'paid' => 0.0]);
            foreach ($b->vouchers->flatMap->listItems->where('status', 'paid') as $item) {
                $events->push(['date' => ($item->list?->date ?? $item->settled_at ?? $item->created_at)->toDateString(), 'number' => $item->list?->number, 'description' => 'Paid'.($item->reference ? " ({$item->reference})" : ''), 'billed' => 0.0, 'paid' => (float) $item->amount]);
            }
        }
        $events = $events->sortBy('date')->values();
        $balance = round($events->filter(fn ($e) => $e['date'] < $v['from'])->sum(fn ($e) => $e['billed'] - $e['paid']), 2);
        $rows = [['date' => $this->d($v['from']), 'number' => '', 'description' => 'Balance brought forward', 'billed' => null, 'paid' => null, 'balance' => $balance]];
        foreach ($events->filter(fn ($e) => $e['date'] >= $v['from'] && $e['date'] <= $v['to']) as $e) {
            $balance = round($balance + $e['billed'] - $e['paid'], 2);
            $rows[] = ['date' => $this->d($e['date']), 'number' => $e['number'], 'description' => $e['description'], 'billed' => $e['billed'] ?: null, 'paid' => $e['paid'] ?: null, 'balance' => $balance];
        }

        return ["{$supplier->name} · {$this->d($v['from'])} to {$this->d($v['to'])}", ['date', 'number', 'description', 'billed', 'paid', 'balance'], $rows];
    }

    private function reportSupplierList(array $v): array
    {
        $open = $this->openBills()->groupBy('supplier_id');
        $rows = \Modules\Procurement\Models\Supplier::orderBy('name')->get()->map(fn ($s) => ['code' => $s->code, 'supplier' => $s->name, 'tin' => $s->tin, 'phone' => $s->phone,
            'status' => $s->is_active ? 'Active' : 'Inactive', 'bills' => isset($open[$s->id]) ? $open[$s->id]->count() : 0, 'balance' => isset($open[$s->id]) ? round($open[$s->id]->sum('balance'), 2) : 0.0])->values();
        $rows->push(['code' => '', 'supplier' => 'Total', 'bills' => $rows->sum('bills'), 'balance' => round($rows->sum('balance'), 2)]);

        return ['Every supplier with what is owed to them', ['code', 'supplier', 'tin', 'phone', 'status', 'bills', 'balance'], $rows->all()];
    }

    private function reportUnpaidRequests(array $v): array
    {
        $rows = \Modules\Payments\Models\PaymentRequest::with(['department', 'payees'])->where('status', 'approved')->orderBy('decided_at')->get()
            ->map(fn ($r) => ['date' => $r->decided_at ? $this->d($r->decided_at->toDateString()) : '', 'number' => $r->number, 'payee' => $r->payees->pluck('name')->join(', '),
                'department' => $r->department?->name, 'description' => $r->title, 'due' => $r->needed_by ? $this->d($r->needed_by->toDateString()) : '',
                'age' => $r->decided_at ? (int) $r->decided_at->startOfDay()->diffInDays(today()) : null, 'amount' => (float) $r->total])->values();
        $rows->push(['date' => 'Total', 'amount' => round($rows->sum('amount'), 2)]);

        return ['Approved, waiting for a payment voucher', ['date', 'number', 'payee', 'department', 'description', 'due', 'age', 'amount'], $rows->all()];
    }

    private function reportUnpaidVouchers(array $v): array
    {
        $rows = PaymentVoucher::with(['request.payees', 'request.department', 'bank', 'listItems'])->where('status', 'approved')->orderBy('date')->get()
            ->map(function ($p) {
                $paid = $p->listItems->where('status', 'paid')->sum('amount');

                return ['date' => $p->date->format('j M Y'), 'number' => $p->number, 'reference' => $p->request?->number, 'payee' => $p->request?->payees->pluck('name')->join(', '),
                    'department' => $p->request?->department?->name, 'bank' => $p->bank?->name, 'age' => (int) $p->date->diffInDays(today()), 'net' => round($p->net - $paid, 2)];
            })->filter(fn ($r) => $r['net'] > 0)->values();
        $rows->push(['date' => 'Total', 'net' => round($rows->sum('net'), 2)]);

        return ['Approved vouchers waiting for a payment list', ['date', 'number', 'reference', 'payee', 'department', 'bank', 'age', 'net'], $rows->all()];
    }

    private function reportCollectionsSummary(array $v): array
    {
        $group = $v['group'] ?: 'month';
        $receipts = Receipt::with(['bank', 'creator'])->where('status', '!=', 'voided')->whereDate('date', '>=', $v['from'])->whereDate('date', '<=', $v['to'])->get();
        $key = fn ($r) => match ($group) {
            'method' => Receipt::METHODS[$r->method] ?? $r->method,
            'bank' => $r->bank?->name ?? '—',
            'cashier' => $r->creator?->display_name ?? '—',
            'kind' => Receipt::KINDS[$r->kind] ?? $r->kind,
            default => $r->date->format('Y-m'),
        };
        $rows = $receipts->groupBy($key)->map(fn ($g, $k) => ['group' => $group === 'month' ? CarbonImmutable::parse($k.'-01')->format('F Y') : $k, 'sort' => $k, 'count' => $g->count(), 'amount' => round($g->sum('amount'), 2)])
            ->sortBy($group === 'month' ? 'sort' : fn ($r) => -$r['amount'])->map(fn ($r) => collect($r)->except('sort')->all())->values();
        $rows->push(['group' => 'Total', 'count' => $rows->sum('count'), 'amount' => round($rows->sum('amount'), 2)]);

        return ["{$this->d($v['from'])} to {$this->d($v['to'])} · voided receipts left out", ['group', 'count', 'amount'], $rows->all()];
    }

    private function assetEntries(?string $from, ?string $to): Builder
    {
        return DB::table('asset_entries as x')->join('assets as a', 'a.id', '=', 'x.asset_id')->where('a.company_id', app(CompanyContext::class)->id())
            ->when($from, fn ($q) => $q->whereDate('x.date', '>=', $from))->when($to, fn ($q) => $q->whereDate('x.date', '<=', $to));
    }

    private function holder($a): string
    {
        return collect([$a->employee ? trim($a->employee->fname.' '.$a->employee->lname) : null, $a->department?->name])->filter()->join(' · ');
    }

    private function reportAssetRegister(array $v): array
    {
        $sums = $this->assetEntries(null, $v['date'])->groupBy('x.asset_id')->selectRaw('x.asset_id, SUM(x.cost) as c, SUM(x.depreciation) as d')->get()->keyBy('asset_id');
        $rows = \Modules\Assets\Models\Asset::with(['category', 'department', 'employee'])->whereIn('id', $sums->keys())
            ->when($v['category_id'], fn ($q, $c) => $q->where('item_category_id', $c))->when($v['department_id'], fn ($q, $d) => $q->where('department_id', $d))->orderBy('number')->get()
            ->map(fn ($a) => ['number' => $a->number, 'asset' => $a->name, 'category' => $a->category?->name, 'serial' => $a->serial_number, 'holder' => $this->holder($a), 'location' => $a->location,
                'acquired' => $this->d($a->acquisition_date->toDateString()), 'cost' => round((float) $sums[$a->id]->c, 2), 'accumulated' => round((float) $sums[$a->id]->d, 2),
                'book_value' => round((float) $sums[$a->id]->c - (float) $sums[$a->id]->d, 2)])->filter(fn ($r) => $r['cost'] != 0 || $r['accumulated'] != 0)->values();
        $rows->push(['number' => 'Total', 'cost' => round($rows->sum('cost'), 2), 'accumulated' => round($rows->sum('accumulated'), 2), 'book_value' => round($rows->sum('book_value'), 2)]);

        return ['As at '.$this->d($v['date']), ['number', 'asset', 'category', 'serial', 'holder', 'location', 'acquired', 'cost', 'accumulated', 'book_value'], $rows->all()];
    }

    private function reportAssetMovements(array $v): array
    {
        $before = CarbonImmutable::parse($v['from'])->subDay()->toDateString();
        $open = $this->assetEntries(null, $before)->groupBy('a.item_category_id')->selectRaw('a.item_category_id as cat, SUM(x.cost) as c, SUM(x.depreciation) as d')->get()->keyBy('cat');
        $moves = $this->assetEntries($v['from'], $v['to'])->groupBy('a.item_category_id', 'x.kind')->selectRaw('a.item_category_id as cat, x.kind, SUM(x.cost) as c, SUM(x.depreciation) as d')->get()->groupBy('cat');
        $cats = \Modules\Inventory\Models\ItemCategory::whereIn('id', $open->keys()->merge($moves->keys())->unique())->orderBy('name')->get();
        $rows = $cats->map(function ($c) use ($open, $moves) {
            $m = $moves[$c->id] ?? collect();
            $sum = fn ($kinds, $field) => round((float) $m->whereIn('kind', $kinds)->sum($field), 2);
            $opening = isset($open[$c->id]) ? round((float) $open[$c->id]->c - (float) $open[$c->id]->d, 2) : 0.0;
            $additions = $sum(['acquisition', 'improvement'], 'c');
            $reval = $sum(['revaluation'], 'c');
            $disposals = round($sum(['disposal', 'transfer_out'], 'c') - $sum(['disposal', 'transfer_out'], 'd'), 2);
            $dep = $sum(['depreciation', 'impairment', 'opening'], 'd');

            return ['category' => $c->name, 'opening' => $opening, 'additions' => $additions, 'revaluation' => $reval, 'disposals' => $disposals, 'depreciation' => -$dep,
                'closing' => round($opening + $additions + $reval + $disposals - $dep, 2)];
        })->values();
        $total = ['category' => 'Total'];
        foreach (['opening', 'additions', 'revaluation', 'disposals', 'depreciation', 'closing'] as $k) {
            $total[$k] = round($rows->sum($k), 2);
        }
        $rows->push($total);

        return ["{$this->d($v['from'])} to {$this->d($v['to'])} · net book value: opening + additions + revaluation − disposals − depreciation = closing", ['category', 'opening', 'additions', 'revaluation', 'disposals', 'depreciation', 'closing'], $rows->all()];
    }

    private function reportDepreciationSchedule(array $v): array
    {
        $period = $this->assetEntries($v['from'], $v['to'])->whereIn('x.kind', ['depreciation', 'impairment'])->groupBy('x.asset_id')->selectRaw('x.asset_id, SUM(x.depreciation) as d')->pluck('d', 'asset_id');
        $end = $this->assetEntries(null, $v['to'])->groupBy('x.asset_id')->selectRaw('x.asset_id, SUM(x.cost) as c, SUM(x.depreciation) as d')->get()->keyBy('asset_id');
        $rows = \Modules\Assets\Models\Asset::with('category')->whereIn('id', $period->keys())->when($v['category_id'], fn ($q, $c) => $q->where('item_category_id', $c))->orderBy('number')->get()
            ->map(fn ($a) => ['number' => $a->number, 'asset' => $a->name, 'category' => $a->category?->name, 'cost' => round((float) ($end[$a->id]->c ?? 0), 2), 'depreciation' => round((float) $period[$a->id], 2),
                'accumulated' => round((float) ($end[$a->id]->d ?? 0), 2), 'book_value' => round((float) ($end[$a->id]->c ?? 0) - (float) ($end[$a->id]->d ?? 0), 2)])->values();
        $rows->push(['number' => 'Total', 'cost' => round($rows->sum('cost'), 2), 'depreciation' => round($rows->sum('depreciation'), 2), 'accumulated' => round($rows->sum('accumulated'), 2), 'book_value' => round($rows->sum('book_value'), 2)]);

        return ["{$this->d($v['from'])} to {$this->d($v['to'])} · includes impairment", ['number', 'asset', 'category', 'cost', 'depreciation', 'accumulated', 'book_value'], $rows->all()];
    }

    private function reportAssetsByHolder(array $v): array
    {
        $rows = \Modules\Assets\Models\Asset::with(['category', 'department', 'employee', 'assignments'])->whereIn('status', \Modules\Assets\Models\Asset::ON_BOOKS)
            ->when($v['department_id'], fn ($q, $d) => $q->where('department_id', $d))->when($v['category_id'], fn ($q, $c) => $q->where('item_category_id', $c))->get()
            ->sortBy(fn ($a) => [$a->department?->name ?? 'zz', $this->holder($a), $a->number])
            ->map(fn ($a) => ['holder' => $this->holder($a) ?: 'Not assigned', 'location' => $a->location, 'number' => $a->number, 'asset' => $a->name, 'category' => $a->category?->name,
                'serial' => $a->serial_number, 'condition' => \Modules\Assets\Models\Asset::CONDITIONS[$a->condition] ?? $a->condition,
                'since' => ($s = $a->assignments->firstWhere('to_date', null)?->from_date) ? $this->d($s->toDateString()) : ''])->values();

        return ['Who holds what, and where it is', ['holder', 'location', 'number', 'asset', 'category', 'serial', 'condition', 'since'], $rows->all()];
    }

    private function reportAssetsDueDisposal(array $v): array
    {
        $rows = \Modules\Assets\Models\Asset::with(['category', 'department', 'employee'])->whereIn('status', ['registered', 'active', 'stored', 'suspended'])
            ->when($v['category_id'], fn ($q, $c) => $q->where('item_category_id', $c))->get()
            ->map(function ($a) {
                $value = $a->valueAt();
                $reasons = array_filter([$a->dep_until && $a->dep_charged_to && $a->dep_charged_to->gte($a->dep_until) ? 'Fully depreciated' : null,
                    in_array($a->condition, ['poor', 'very_poor'], true) ? 'Condition '.\Modules\Assets\Models\Asset::CONDITIONS[$a->condition] : null, $a->status === 'suspended' ? 'Suspended' : null]);

                return $reasons ? ['number' => $a->number, 'asset' => $a->name, 'category' => $a->category?->name, 'holder' => $this->holder($a), 'reason' => implode(', ', $reasons),
                    'cost' => $value['cost'], 'book_value' => $value['book_value']] : null;
            })->filter()->sortBy('number')->values();

        return ['Candidates for the disposal board', ['number', 'asset', 'category', 'holder', 'reason', 'cost', 'book_value'], $rows->all()];
    }

    private function reportAssetDisposals(array $v): array
    {
        $rows = \Modules\Assets\Models\AssetDisposal::with('asset')->where('status', 'approved')->whereDate('date', '>=', $v['from'])->whereDate('date', '<=', $v['to'])->orderBy('date')->get()
            ->map(fn ($d) => ['date' => $this->d($d->date->toDateString()), 'number' => $d->number, 'asset' => "{$d->asset->number} {$d->asset->name}", 'method' => \Modules\Assets\Models\AssetDisposal::METHODS[$d->method],
                'reason' => $d->reason, 'book_value' => (float) $d->book_value, 'proceeds' => $d->proceeds, 'gain_loss' => (float) $d->gain_loss])->values();
        $rows->push(['date' => 'Total', 'book_value' => round($rows->sum('book_value'), 2), 'proceeds' => round($rows->sum('proceeds'), 2), 'gain_loss' => round($rows->sum('gain_loss'), 2)]);

        return ["{$this->d($v['from'])} to {$this->d($v['to'])}", ['date', 'number', 'asset', 'method', 'reason', 'book_value', 'proceeds', 'gain_loss'], $rows->all()];
    }

    private function reportAssetTransfers(array $v): array
    {
        $rows = \Modules\Assets\Models\AssetTransfer::with(['asset', 'toBranch', 'toDepartment', 'toEmployee'])->whereIn('status', ['approved', 'returned'])
            ->whereDate('date', '>=', $v['from'])->whereDate('date', '<=', $v['to'])->orderBy('date')->get()
            ->map(fn ($t) => ['date' => $this->d($t->date->toDateString()), 'number' => $t->number, 'asset' => "{$t->asset->number} {$t->asset->name}",
                'kind' => \Modules\Assets\Models\AssetTransfer::KINDS[$t->kind].($t->permanent ? '' : ' (temporary)'), 'destination' => $t->destination(),
                'return_date' => $t->return_date ? $this->d($t->return_date->toDateString()) : '',
                'status' => $t->status === 'returned' ? 'Returned' : ((! $t->permanent && $t->return_date?->lt(today())) ? 'Overdue for return' : 'Done'), 'reason' => $t->reason])->values();

        return ["{$this->d($v['from'])} to {$this->d($v['to'])}", ['date', 'number', 'asset', 'kind', 'destination', 'return_date', 'status', 'reason'], $rows->all()];
    }

    private function reportTenderRegister(array $v): array
    {
        $rows = \Modules\Procurement\Models\Tender::with(['method', 'requisition.department'])->withCount('bids')->whereDate('created_at', '>=', $v['from'])->whereDate('created_at', '<=', $v['to'])
            ->orderBy('id')->get()->map(fn ($t) => ['number' => $t->number, 'tender' => $t->title, 'method' => $t->method?->name, 'department' => $t->requisition?->department?->name,
                'closing' => $this->d($t->closing_at->toDateString()), 'bidders' => $t->bids_count, 'estimate' => (float) $t->requisition?->total,
                'status' => \Modules\Procurement\Models\Tender::STATUS_LABELS[$t->status] ?? $t->status])->values();

        return ["Tenders made {$this->d($v['from'])} to {$this->d($v['to'])}", ['number', 'tender', 'method', 'department', 'closing', 'bidders', 'estimate', 'status'], $rows->all()];
    }

    private function openedTender(array $v): \Modules\Procurement\Models\Tender
    {
        $t = $v['tender_id'] ? \Modules\Procurement\Models\Tender::with(['bids.supplier', 'bids.lines.tenderLine', 'lines', 'method'])->whereNotNull('opened_at')->find((int) $v['tender_id']) : null;
        if (! $t) {
            throw new MissingChoice('Choose a tender whose bids have been opened.');
        }

        return $t;
    }

    private function reportBidOpening(array $v): array
    {
        $t = $this->openedTender($v);
        $rows = [];
        foreach ($t->bids as $b) {
            foreach ($t->lots() as $lot) {
                if ($b->lines->contains(fn ($l) => $l->tenderLine->lot === $lot)) {
                    $rows[] = ['supplier' => $b->supplier->name, 'lot' => $lot, 'read_out' => $b->priceFor($lot), 'documents' => $b->documents_complete ? 'Complete' : 'Missing: '.$b->missing_documents,
                        'reason' => $b->bid_security ?: ''];
                }
            }
        }

        return ["{$t->number} {$t->title} · opened ".$t->opened_at->format('d M Y H:i').($t->opening_attendees ? " · attendees: {$t->opening_attendees}" : ''), ['supplier', 'lot', 'read_out', 'documents', 'reason'], $rows];
    }

    private function reportEvaluationSummary(array $v): array
    {
        $t = $this->openedTender($v);
        $rows = [];
        foreach (app(\Modules\Procurement\Actions\ManageTenders::class)->ranking($t) as $lot => $ranked) {
            foreach ($ranked as $r) {
                $rows[] = ['lot' => $lot, 'rank' => $r['rank'], 'supplier' => $r['supplier'], 'amount' => $r['price'], 'technical' => $r['technical'], 'financial' => $r['financial'], 'combined' => $r['combined']];
            }
        }
        foreach ($t->bids->where('responsive', false) as $b) {
            $rows[] = ['lot' => '', 'rank' => null, 'supplier' => $b->supplier->name, 'amount' => null, 'technical' => $b->technical_score, 'financial' => null, 'combined' => null, 'responsive' => 'Not responsive'];
        }

        return ["{$t->number} {$t->title} · ".\Modules\Procurement\Models\ProcurementMethod::EVALUATION[$t->method->evaluation], ['lot', 'rank', 'supplier', 'amount', 'technical', 'financial', 'combined'], $rows];
    }

    private function reportTenderAwards(array $v): array
    {
        $rows = \Modules\Procurement\Models\TenderAward::with(['tender.method', 'tender.lines.requisitionLine', 'supplier'])->whereNotNull('letter_sent_at')
            ->whereDate('letter_sent_at', '>=', $v['from'])->whereDate('letter_sent_at', '<=', $v['to'])->orderBy('id')->get()
            ->map(function ($a) {
                $estimate = round($a->tender->lines->where('lot', $a->lot)->sum(fn ($l) => $l->quantity * $l->requisitionLine->estimated_unit_cost), 2);

                return ['tender' => "{$a->tender->number} {$a->tender->title}", 'lot' => $a->lot, 'supplier' => $a->supplier->name, 'method' => $a->tender->method?->name,
                    'date' => $this->d($a->letter_sent_at->toDateString()), 'estimate' => $estimate, 'awarded' => $a->amount, 'saving' => round($estimate - $a->amount, 2)];
            })->values();
        $rows->push(['tender' => 'Total', 'estimate' => round($rows->sum('estimate'), 2), 'awarded' => round($rows->sum('awarded'), 2), 'saving' => round($rows->sum('saving'), 2)]);

        return ["Award letters {$this->d($v['from'])} to {$this->d($v['to'])}", ['tender', 'lot', 'supplier', 'method', 'date', 'estimate', 'awarded', 'saving'], $rows->all()];
    }

    private function reportContractRegister(array $v): array
    {
        $rows = \Modules\Procurement\Models\Contract::with('supplier')->when($v['status'], fn ($q, $s) => $q->where('status', $s))->orderBy('id')->get()
            ->map(function ($c) {
                $f = $c->figures();

                return ['number' => $c->number, 'contract' => $c->title, 'supplier' => $c->supplier?->name, 'value' => $f['value'], 'varied' => $f['varied'], 'certified' => $c->byCertificate() ? $f['certified'] : $f['ordered'],
                    'paid' => $f['paid'], 'retained' => $f['retained'], 'balance' => $f['balance'], 'end_date' => $this->d($c->end_date->toDateString()),
                    'status' => \Modules\Procurement\Models\Contract::STATUS_LABELS[$c->status] ?? $c->status];
            })->values();
        $total = ['number' => 'Total'];
        foreach (['value', 'varied', 'certified', 'paid', 'retained', 'balance'] as $k) {
            $total[$k] = round($rows->sum($k), 2);
        }
        $rows->push($total);

        return ['Value, variations, used, paid, retained and balance', ['number', 'contract', 'supplier', 'value', 'varied', 'certified', 'paid', 'retained', 'balance', 'end_date', 'status'], $rows->all()];
    }

    private function reportContractsExpiring(array $v): array
    {
        $limit = CarbonImmutable::today()->addDays((int) ($v['days'] ?: 30))->toDateString();
        $rows = \Modules\Procurement\Models\Contract::with('supplier')->where('status', 'active')->where(fn ($q) => $q->whereDate('end_date', '<=', $limit)->orWhereDate('security_expiry', '<=', $limit))
            ->orderBy('end_date')->get()->map(fn ($c) => ['number' => $c->number, 'contract' => $c->title, 'supplier' => $c->supplier?->name, 'end_date' => $this->d($c->end_date->toDateString()),
                'security' => $c->security_expiry ? $this->d($c->security_expiry->toDateString()) : '', 'balance' => $c->figures()['balance']])->values();

        return ["Ending or with a security expiring by {$this->d($limit)}", ['number', 'contract', 'supplier', 'end_date', 'security', 'balance'], $rows->all()];
    }

    private function reportOrdersOutstanding(array $v): array
    {
        $rows = \Modules\Procurement\Models\PurchaseOrder::with(['supplier', 'lines'])->whereIn('status', ['approved', 'partial'])->orderBy('delivery_date')->get()
            ->map(function ($po) {
                $ordered = round($po->lines->sum('amount'), 2);
                $received = round($po->lines->sum(fn ($l) => $l->received_quantity * $l->unit_price), 2);

                return ['number' => $po->number, 'supplier' => $po->supplier?->name, 'date' => $this->d($po->order_date->toDateString()), 'due' => round($ordered - $received, 2),
                    'ordered' => $ordered, 'received' => $received, 'end_date' => $po->delivery_date ? $this->d($po->delivery_date->toDateString()) : '',
                    'status' => $po->delivery_date && $po->delivery_date->isPast() ? 'Late' : 'On schedule'];
            })->values();
        $rows->push(['number' => 'Total', 'ordered' => round($rows->sum('ordered'), 2), 'received' => round($rows->sum('received'), 2), 'due' => round($rows->sum('due'), 2)]);

        return ['Approved orders not fully delivered', ['number', 'supplier', 'date', 'end_date', 'ordered', 'received', 'due', 'status'], $rows->all()];
    }

    private function reportSupplierPerformance(array $v): array
    {
        $grns = \Modules\Procurement\Models\GoodsReceipt::with(['order', 'lines'])->where('status', 'accepted')->whereDate('received_on', '>=', $v['from'])->whereDate('received_on', '<=', $v['to'])->get()->groupBy(fn ($g) => $g->order?->supplier_id);
        $complaints = \Modules\Procurement\Models\TenderComplaint::whereDate('received_on', '>=', $v['from'])->whereDate('received_on', '<=', $v['to'])->get()->groupBy('supplier_id');
        $rows = \Modules\Procurement\Models\Supplier::whereIn('id', $grns->keys()->merge($complaints->keys())->filter())->orderBy('name')->get()->map(function ($s) use ($grns, $complaints) {
            $g = $grns[$s->id] ?? collect();
            $onTime = $g->filter(fn ($r) => ! $r->order?->delivery_date || $r->received_on->lte($r->order->delivery_date))->count();
            $delivered = $g->flatMap->lines->sum('delivered_quantity');
            $rejected = $g->flatMap->lines->sum('rejected_quantity');

            return ['supplier' => $s->name, 'deliveries' => $g->count(), 'on_time' => $g->count() ? round($onTime / $g->count() * 100, 1) : null,
                'rejected' => $delivered > 0 ? round($rejected / $delivered * 100, 1) : null, 'complaints' => isset($complaints[$s->id]) ? $complaints[$s->id]->count() : 0];
        })->values();

        return ["{$this->d($v['from'])} to {$this->d($v['to'])} · on time against the order's delivery date", ['supplier', 'deliveries', 'on_time', 'rejected', 'complaints'], $rows->all()];
    }

    /** @return \Illuminate\Support\Collection<int, array{method: string, supplier_id: int, amount: float}> */
    private function spend(array $v)
    {
        $orders = \Modules\Procurement\Models\PurchaseOrder::with('contract.tender.method')->whereNotIn('status', ['draft', 'rejected', 'cancelled', 'pending'])
            ->whereDate('order_date', '>=', $v['from'])->whereDate('order_date', '<=', $v['to'])->get()
            ->map(fn ($po) => ['method' => $po->contract?->tender?->method?->name ?? (\Modules\Procurement\Models\PurchaseOrder::METHODS[$po->method] ?? $po->method), 'supplier_id' => $po->supplier_id, 'amount' => (float) $po->total]);
        $certs = \Modules\Procurement\Models\ContractCertificate::with('contract.tender.method')->where('status', 'approved')->whereDate('date', '>=', $v['from'])->whereDate('date', '<=', $v['to'])->get()
            ->map(fn ($c) => ['method' => $c->contract->tender?->method?->name ?? 'Contract', 'supplier_id' => $c->contract->supplier_id, 'amount' => (float) $c->gross]);

        return $orders->merge($certs);
    }

    private function reportSpendByMethod(array $v): array
    {
        $spend = $this->spend($v);
        $total = max($spend->sum('amount'), 0.01);
        $rows = $spend->groupBy('method')->map(fn ($g, $m) => ['method' => $m, 'count' => $g->count(), 'amount' => round($g->sum('amount'), 2), 'share' => round($g->sum('amount') / $total * 100, 1)])
            ->sortByDesc('amount')->values();
        $rows->push(['method' => 'Total', 'count' => $rows->sum('count'), 'amount' => round($rows->sum('amount'), 2), 'share' => $rows->isEmpty() ? 0 : 100]);

        return ["Orders and certificates {$this->d($v['from'])} to {$this->d($v['to'])}", ['method', 'count', 'amount', 'share'], $rows->all()];
    }

    private function reportSpecialGroupShare(array $v): array
    {
        $spend = $this->spend($v);
        $groups = \Modules\Procurement\Models\Supplier::whereIn('id', $spend->pluck('supplier_id')->unique())->pluck('special_group', 'id');
        $total = max($spend->sum('amount'), 0.01);
        $rows = $spend->groupBy(fn ($r) => $groups[$r['supplier_id']] ?? '')->map(fn ($g, $k) => ['group_name' => $k ? (\Modules\Procurement\Models\Supplier::SPECIAL_GROUPS[$k] ?? $k) : 'Not a special group',
            'count' => $g->count(), 'amount' => round($g->sum('amount'), 2), 'share' => round($g->sum('amount') / $total * 100, 1)])->sortByDesc('amount')->values();
        $rows->push(['group_name' => 'Total', 'count' => $rows->sum('count'), 'amount' => round($rows->sum('amount'), 2), 'share' => $rows->isEmpty() ? 0 : 100]);

        return ["Orders and certificates {$this->d($v['from'])} to {$this->d($v['to'])}", ['group_name', 'count', 'amount', 'share'], $rows->all()];
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

    private function stockRows(array $v)
    {
        return \Modules\Inventory\Models\StockBalance::with(['store', 'item.unit', 'item.category', 'item.levels'])->whereHas('store', fn ($s) => $s->where('kind', '!=', 'transit'))
            ->when($v['store_id'] ?? null, fn ($q, $s) => $q->where('store_id', $s))->get();
    }

    private function reportStockBalance(array $v): array
    {
        $rows = $this->stockRows($v)->filter(fn ($b) => $b->quantity != 0)->sortBy(fn ($b) => [$b->store?->name, $b->item?->name])
            ->map(fn ($b) => ['store' => $b->store?->name, 'code' => $b->item?->code, 'item' => $b->item?->name, 'unit' => $b->item?->unit?->code, 'quantity' => $b->quantity, 'avg_cost' => $b->averageCost(), 'value' => $b->value])->values();
        $rows->push(['store' => 'Total', 'value' => round($rows->sum('value'), 2)]);

        return ['On hand now', ['store', 'code', 'item', 'unit', 'quantity', 'avg_cost', 'value'], $rows->all()];
    }

    private function reportStockMovements(array $v): array
    {
        $rows = \Modules\Inventory\Models\StockMovement::with(['store', 'item', 'department', 'layer'])->whereDate('date', '>=', $v['from'])->whereDate('date', '<=', $v['to'])
            ->when($v['store_id'] ?? null, fn ($q, $s) => $q->where('store_id', $s))->when($v['type'] ?? null, fn ($q, $t) => $q->where('type', $t))->orderBy('date')->orderBy('id')->limit(5000)->get()
            ->map(fn ($m) => ['date' => $m->date->format('j M Y'), 'store' => $m->store?->name, 'reference' => $m->reference, 'type' => \Modules\Inventory\Models\StockMovement::TYPES[$m->type] ?? $m->type,
                'item' => $m->item?->name, 'batch' => $m->layer?->batch_number, 'department' => $m->department?->name, 'quantity' => $m->quantity, 'value' => $m->value]);

        return ["{$this->d($v['from'])} to {$this->d($v['to'])}", ['date', 'store', 'reference', 'type', 'item', 'batch', 'department', 'quantity', 'value'], $rows->all()];
    }

    private function reportStockConsumption(array $v): array
    {
        $rows = \Modules\Inventory\Models\StockMovement::with(['department', 'item'])->where('type', 'issue')->whereDate('date', '>=', $v['from'])->whereDate('date', '<=', $v['to'])
            ->when($v['store_id'] ?? null, fn ($q, $s) => $q->where('store_id', $s))->get()->groupBy(fn ($m) => ($m->department?->name ?? 'No department').'|'.$m->item_id)
            ->map(fn ($g) => ['department' => $g->first()->department?->name ?? 'No department', 'item' => $g->first()->item?->name, 'quantity' => round(-$g->sum('quantity'), 4), 'value' => round(-$g->sum('value'), 2)])
            ->sortBy([['department', 'asc'], ['value', 'desc']])->values();
        $rows->push(['department' => 'Total', 'value' => round($rows->sum('value'), 2)]);

        return ["{$this->d($v['from'])} to {$this->d($v['to'])} · issues at cost", ['department', 'item', 'quantity', 'value'], $rows->all()];
    }

    private function reportStockExpiry(array $v): array
    {
        $days = \Modules\Inventory\Models\InventorySetting::current()->expiry_warning_days;
        $rows = \Modules\Inventory\Models\StockLayer::with(['store', 'item'])->where('quantity_left', '>', 0)->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', today()->addDays($days))
            ->when($v['store_id'] ?? null, fn ($q, $s) => $q->where('store_id', $s))->orderBy('expiry_date')->get()
            ->map(fn ($l) => ['store' => $l->store?->name, 'item' => $l->item?->name, 'batch' => $l->batch_number, 'expiry' => $l->expiry_date->format('j M Y'),
                'days' => (int) today()->diffInDays($l->expiry_date, false), 'quantity' => $l->quantity_left, 'value' => round($l->quantity_left * $l->unit_cost, 2)]);

        return ["Expiring within {$days} days, and expired (negative days)", ['store', 'item', 'batch', 'expiry', 'days', 'quantity', 'value'], $rows->all()];
    }

    private function reportStockReorder(array $v): array
    {
        $rows = $this->stockRows($v)->map(function ($b) {
            $level = $b->item?->levels->firstWhere('store_id', $b->store_id)?->reorder_level ?? $b->item?->reorder_level;

            return $level !== null && $b->item?->is_active && $b->quantity <= $level ? ['store' => $b->store?->name, 'code' => $b->item?->code, 'item' => $b->item?->name, 'unit' => $b->item?->unit?->code, 'quantity' => $b->quantity, 'reorder' => $level] : null;
        })->filter()->values();

        return ['Raise a purchase requisition for these', ['store', 'code', 'item', 'unit', 'quantity', 'reorder'], $rows->all()];
    }

    private function reportStockSlow(array $v): array
    {
        $days = \Modules\Inventory\Models\InventorySetting::current()->slow_moving_days;
        $rows = $this->stockRows($v)->filter(fn ($b) => $b->quantity > 0 && (! $b->last_issue_on || $b->last_issue_on->lt(today()->subDays($days))))
            ->map(fn ($b) => ['store' => $b->store?->name, 'item' => $b->item?->name, 'quantity' => $b->quantity, 'value' => $b->value, 'last_issue' => $b->last_issue_on?->format('j M Y') ?? 'Never'])
            ->sortByDesc('value')->values();

        return ["No issues in the last {$days} days", ['store', 'item', 'quantity', 'value', 'last_issue'], $rows->all()];
    }

    private function reportStockTransit(array $v): array
    {
        $rows = \Modules\Inventory\Models\StockBalance::with(['item', 'store'])->whereHas('store', fn ($s) => $s->where('kind', 'transit'))->where('quantity', '!=', 0)->get()
            ->map(fn ($b) => ['item' => $b->item?->name, 'quantity' => $b->quantity, 'value' => $b->value]);

        return ['Dispatched and not yet received, including shortages to resolve', ['item', 'quantity', 'value'], $rows->all()];
    }

    private function reportStockCountVariance(array $v): array
    {
        $rows = \Modules\Inventory\Models\StockDocumentLine::with(['item'])->whereHas('document', fn ($d) => $d->where('kind', 'count')->where('status', 'posted')->whereDate('date', '>=', $v['from'])->whereDate('date', '<=', $v['to'])
            ->when($v['store_id'] ?? null, fn ($q, $s) => $q->where('store_id', $s)))->where('base_quantity', '!=', 0)->with('document.store')->get()
            ->map(fn ($l) => ['date' => $l->document->date->format('j M Y'), 'reference' => $l->document->number, 'store' => $l->document->store?->name, 'item' => $l->item?->name, 'system' => $l->system_quantity,
                'counted' => $l->quantity, 'variance' => $l->base_quantity, 'value' => $l->value, 'reason' => $l->reason]);

        return ["{$this->d($v['from'])} to {$this->d($v['to'])}", ['date', 'reference', 'store', 'item', 'system', 'counted', 'variance', 'value', 'reason'], $rows->all()];
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
