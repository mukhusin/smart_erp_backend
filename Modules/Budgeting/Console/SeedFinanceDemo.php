<?php

namespace Modules\Budgeting\Console;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Actions\ManageJournals;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Models\FundSource;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Accounting\Services\PostingService;
use Modules\Budgeting\Actions\ManageBudgets;
use Modules\Budgeting\Actions\ManageTransfers;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Models\DepartmentBudget;
use Modules\Planning\Models\AnnualPlan;
use Modules\Planning\Models\DepartmentPlan;
use Modules\Planning\Models\StrategicPlan;
use Modules\Planning\Models\SubActivity;
use Modules\Tasks\Models\Task;
use Modules\Tasks\Models\TaskActivity;

class SeedFinanceDemo extends Command
{
    protected $signature = 'erp:demo-finance {--company=1 : Company id} {--departments=5 : How many departments get plans and budgets} {--force : Run even if the company already has finance data}';

    protected $description = 'Fill a test company with demo planning, budgeting and accounting data (never in production)';

    private const CHART = [
        ['1000', 'Assets', 'asset', null, false, false], ['1100', 'CRDB bank account', 'asset', '1000', true, false], ['1110', 'NMB bank account', 'asset', '1000', true, false],
        ['1150', 'Petty cash', 'asset', '1000', true, false], ['1200', 'Patient receivables', 'asset', '1000', true, false], ['1210', 'Insurance (NHIF) receivable', 'asset', '1000', true, false],
        ['1300', 'Staff advances (imprest)', 'asset', '1000', true, false], ['1400', 'Medicines inventory', 'asset', '1000', true, false],
        ['2000', 'Liabilities', 'liability', null, false, false], ['2100', 'Payees and suppliers payable', 'liability', '2000', true, false], ['2200', 'PAYE payable', 'liability', '2000', true, false],
        ['2210', 'NSSF payable', 'liability', '2000', true, false], ['2220', 'Withholding tax payable', 'liability', '2000', true, false], ['2300', 'Salaries payable', 'liability', '2000', true, false],
        ['3000', 'Equity', 'equity', null, false, false], ['3100', 'Capital', 'equity', '3000', true, false], ['3200', 'Accumulated surplus', 'equity', '3000', true, false],
        ['4000', 'Revenue', 'revenue', null, false, false], ['4100', 'Patient fees (cash)', 'revenue', '4000', true, true], ['4110', 'Insurance revenue (NHIF)', 'revenue', '4000', true, true],
        ['4120', 'Pharmacy sales', 'revenue', '4000', true, true], ['4200', 'Grants and donations', 'revenue', '4000', true, true],
        ['5000', 'Expenses', 'expense', null, false, false], ['5100', 'Medical supplies', 'expense', '5000', true, true], ['5110', 'Laboratory reagents', 'expense', '5000', true, true],
        ['5200', 'Salaries and wages', 'expense', '5000', true, true], ['5210', 'Staff allowances', 'expense', '5000', true, true], ['5300', 'Electricity and water', 'expense', '5000', true, true],
        ['5310', 'Fuel and transport', 'expense', '5000', true, true], ['5320', 'Travel and per diem', 'expense', '5000', true, true], ['5400', 'Training and workshops', 'expense', '5000', true, true],
        ['5500', 'Repairs and maintenance', 'expense', '5000', true, true], ['5600', 'Office supplies and printing', 'expense', '5000', true, true], ['5610', 'Advertising and publicity', 'expense', '5000', true, true],
        ['5700', 'Bank charges', 'expense', '5000', true, true],
    ];

    private const PLANS = [
        'nurs' => [
            ['Infection prevention and control', 'SO2', 'Hand hygiene compliance ≥ 90%', [['Buy hand hygiene and PPE supplies', '5100', 'cartons', 40, 85000, 4], ['Train ward staff on IPC', '5400', 'sessions', 6, 1200000, 1]]],
            ['Quality of nursing care', 'SO1', '12 nursing audits', [['Monthly nursing care audits', '5600', 'audits', 12, 150000, 1], ['Nurse refresher course', '5400', 'nurses', 30, 250000, 1]]],
        ],
        'clinic' => [
            ['Laboratory services', 'SO1', 'Turnaround time under 2 hours', [['Buy laboratory reagents', '5110', 'kits', 60, 420000, 4], ['Service laboratory analysers', '5500', 'services', 4, 2500000, 1]]],
            ['Community outreach clinics', 'SO3', '24 outreach clinics', [['Transport for outreach clinics', '5310', 'trips', 24, 350000, 1], ['Per diem for outreach team', '5320', 'person-days', 120, 60000, 1]]],
        ],
        'finance' => [
            ['Revenue collection', 'SO4', 'NHIF claims paid within 60 days', [['Process NHIF claims', '5600', 'months', 12, 300000, 1], ['Bank charges', '5700', 'months', 12, 450000, 1]]],
            ['Hospital revenue', 'SO4', 'Revenue grows 10%', [['Patient fees (cash)', '4100', 'months', 12, 95000000, 1], ['Insurance revenue (NHIF)', '4110', 'months', 12, 140000000, 1]]],
        ],
        'market' => [
            ['Community health awareness', 'SO3', '10 health campaigns', [['Health campaign materials', '5610', 'campaigns', 10, 1800000, 1], ['Radio and social media adverts', '5610', 'months', 12, 900000, 1]]],
        ],
        'generic' => [
            ['Service delivery improvement', 'SO1', 'Client satisfaction ≥ 85%', [['Supplies for the department', '5100', 'months', 12, 650000, 1], ['Staff training', '5400', 'staff', 10, 300000, 1]]],
            ['Equipment and premises', 'SO1', 'Equipment downtime under 5%', [['Repairs and maintenance', '5500', 'jobs', 8, 900000, 1], ['Electricity and water share', '5300', 'months', 12, 1200000, 1]]],
        ],
    ];

    public function handle(CompanyContext $context): int
    {
        if (app()->environment('production')) {
            $this->error('Demo data is never created in production.');

            return self::FAILURE;
        }
        $company = Company::find((int) $this->option('company'));
        if (! $company) {
            $this->error('Company not found.');

            return self::FAILURE;
        }
        mt_srand(42);

        return $context->run($company->id, function () use ($company) {
            if (! $this->option('force') && (JournalEntry::exists() || Budget::exists())) {
                $this->error("{$company->name} already has journals or budgets. Use --force to add demo data anyway.");

                return self::FAILURE;
            }
            $admin = User::where('company_id', $company->id)->whereHas('roles', fn ($q) => $q->where('name', 'company-admin'))->orderBy('id')->first()
                ?? User::where('company_id', $company->id)->orderBy('id')->first();
            auth()->setUser($admin);
            setPermissionsTeamId($company->id);

            DB::transaction(function () use ($company, $admin) {
                $year = $this->fiscalYear($company);
                $this->info("Fiscal year {$year->name}");
                AccountingSetting::current()->fill(['budget_phasing' => 'quarter', 'budget_control' => 'warn', 'budget_release' => 'period'])->save();
                $acc = $this->chart();
                $funds = $this->funds();
                $this->banks($acc);
                $this->opening($year, $acc, $admin);
                $departments = Department::whereNull('parent_id')->where('is_active', true)->withCount(['employees' => fn ($q) => $q->active()])
                    ->orderByDesc('employees_count')->take((int) $this->option('departments'))->get();
                [$plan, $activities] = $this->planning($year, $departments, $admin);
                $budget = $this->budget($year, $plan, $departments, $activities, $acc, $funds, $admin);
                $this->spending($year, $budget, $acc, $admin);
                $this->tasks($activities, $admin);
                $this->pending($budget, $acc, $admin);
            });
            $this->info('Done. Open Planning, Budgeting and Accounting to see the demo data.');

            return self::SUCCESS;
        });
    }

    private function fiscalYear(Company $company): FiscalYear
    {
        if ($year = FiscalYear::containing(today())) {
            return $year;
        }
        [$m, $d] = array_map('intval', explode('-', $company->fiscal_year_start ?: '01-01'));
        $start = Carbon::create(today()->year, $m ?: 1, $d ?: 1);
        if ($start->gt(today())) {
            $start->subYear();
        }

        return app(FiscalCalendar::class)->createYear($start->toDateString());
    }

    private function chart(): array
    {
        $ids = Account::pluck('id', 'code')->all();
        foreach (self::CHART as [$code, $name, $type, $parent, $postable, $budgetable]) {
            if (! isset($ids[$code])) {
                $ids[$code] = Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'parent_id' => $parent ? $ids[$parent] : null,
                    'is_postable' => $postable, 'is_budgetable' => $budgetable, 'is_active' => true])->id;
            }
        }
        $this->line('Chart of accounts: '.count(self::CHART).' accounts');

        return $ids;
    }

    private function funds(): array
    {
        $out = [];
        foreach ([['OWN', 'Own revenue', 'own'], ['NHIF', 'National Health Insurance Fund', 'insurance'], ['GRANT', 'Maternal health partner grant', 'donor']] as [$code, $name, $kind]) {
            $out[$code] = FundSource::firstOrCreate(['code' => $code], ['name' => $name, 'kind' => $kind, 'is_active' => true])->id;
        }

        return $out;
    }

    private function banks(array $acc): array
    {
        $out = [];
        foreach ([['CRDB main account', 'bank', '1100', 'CRDB Bank', '0150 123 456 700'], ['NMB collections', 'bank', '1110', 'NMB Bank', '2040 998 877'], ['Petty cash (cashier)', 'cash', '1150', null, null]] as [$name, $kind, $code, $bank, $no]) {
            $out[$code] = CashBankAccount::firstOrCreate(['name' => $name], ['kind' => $kind, 'account_id' => $acc[$code], 'bank_name' => $bank, 'account_number' => $no, 'currency' => 'TZS', 'is_active' => true])->id;
        }

        return $out;
    }

    private function opening(FiscalYear $year, array $acc, User $by): void
    {
        app(PostingService::class)->post($year->start_date->toDateString(), 'Opening balances', [
            ['account_id' => $acc['1100'], 'debit' => 850000000], ['account_id' => $acc['1110'], 'debit' => 240000000], ['account_id' => $acc['1150'], 'debit' => 3000000],
            ['account_id' => $acc['3100'], 'credit' => 1093000000],
        ], $by, 'opening');
    }

    private function planning(FiscalYear $year, $departments, User $by): array
    {
        $start = $year->start_date->year;
        $strategic = StrategicPlan::create(['title' => "Strategic plan {$start}–".($start + 4), 'start_year' => $start, 'end_year' => $start + 4, 'status' => 'active', 'created_by' => $by->id,
            'vision' => 'Quality, affordable health care for every family we serve.', 'mission' => 'To provide safe, patient-centred care through skilled people and sound management.']);
        $objectives = [];
        foreach ([['SO1', 'Improve quality and safety of care'], ['SO2', 'Reduce hospital-acquired infections'], ['SO3', 'Reach more people through outreach and awareness'], ['SO4', 'Strengthen financial sustainability']] as $i => [$code, $title]) {
            $objectives[$code] = $strategic->objectives()->create(['code' => $code, 'title' => $title, 'sort_order' => $i + 1])->id;
        }
        $plan = AnnualPlan::create(['strategic_plan_id' => $strategic->id, 'title' => "Annual plan {$year->name}", 'year' => $start, 'start_date' => $year->start_date,
            'end_date' => $year->end_date, 'status' => 'open', 'created_by' => $by->id]);
        $activities = [];
        foreach ($departments as $di => $dept) {
            DepartmentPlan::create(['annual_plan_id' => $plan->id, 'department_id' => $dept->id, 'status' => 'approved', 'submitted_by' => $by->id, 'submitted_at' => now(), 'decided_at' => now()]);
            foreach (self::PLANS[$this->kind($dept->name)] as $ai => [$title, $so, $target, $subs]) {
                $code = ($di + 1).'.'.($ai + 1);
                $activity = TaskActivity::create(['department_id' => $dept->id, 'annual_plan_id' => $plan->id, 'strategic_objective_id' => $objectives[$so], 'code' => $code, 'year' => $start,
                    'title' => $title, 'target' => $target, 'kpi' => $target, 'quarters' => '1,2,3,4', 'is_active' => true, 'created_by' => $by->id]);
                foreach ($subs as $si => $sub) {
                    $subActivity = SubActivity::create(['task_activity_id' => $activity->id, 'code' => $code.'.'.($si + 1), 'title' => $sub[0], 'quarters' => '1,2,3,4', 'sort_order' => $si + 1]);
                    $activities[] = ['department' => $dept, 'activity' => $activity, 'sub' => $subActivity, 'spec' => $sub];
                }
            }
        }
        $this->line('Planning: strategic plan, 4 objectives, '.count($departments).' department plans, '.count($activities).' sub-activities');

        return [$plan, $activities];
    }

    private function budget(FiscalYear $year, AnnualPlan $plan, $departments, array $activities, array $acc, array $funds, User $by): Budget
    {
        $manage = app(ManageBudgets::class);
        $budget = Budget::create(['fiscal_year_id' => $year->id, 'annual_plan_id' => $plan->id, 'title' => "Budget {$year->name}", 'phasing' => 'quarter', 'status' => 'open', 'created_by' => $by->id]);
        foreach ($departments as $dept) {
            DepartmentBudget::create(['budget_id' => $budget->id, 'department_id' => $dept->id, 'status' => 'approved', 'submitted_by' => $by->id, 'submitted_at' => now(), 'decided_at' => now()]);
        }
        foreach ($activities as $a) {
            [$what, $code, $unit, $qty, $cost, $freq] = $a['spec'];
            $fund = str_starts_with($code, '41') ? ($code === '4110' ? $funds['NHIF'] : $funds['OWN']) : (str_contains(strtolower($a['activity']->title), 'outreach') ? $funds['GRANT'] : $funds['OWN']);
            $total = round($qty * $cost * $freq, 2);
            $split = $manage->split($total, 4, null);
            $item = BudgetItem::create(['budget_id' => $budget->id, 'department_id' => $a['department']->id, 'task_activity_id' => $a['activity']->id, 'sub_activity_id' => $a['sub']->id,
                'account_id' => $acc[$code], 'fund_source_id' => $fund, 'description' => $what, 'quantity' => $qty, 'unit' => $unit, 'unit_cost' => $cost, 'frequency' => $freq,
                'original_amount' => $total, 'revised_amount' => $total]);
            foreach ($split as $i => $amount) {
                $item->periods()->create(['period' => $i + 1, 'amount' => $amount]);
            }
        }
        $manage->approved($budget->refresh());
        $months = ($today = today())->year * 12 + $today->month - ($year->start_date->year * 12 + $year->start_date->month);
        foreach (range(1, min(4, intdiv(max(0, $months), 3) + 1)) as $period) {
            $manage->released($budget->releases()->create(['period' => $period, 'status' => 'pending', 'requested_by' => $by->id]));
        }
        $this->line('Budget: '.$budget->items()->count().' items, '.number_format((float) $budget->items()->sum('original_amount'), 0).' TZS, approved, '.$budget->releases()->count().' quarter(s) open');

        return $budget;
    }

    private function spending(FiscalYear $year, Budget $budget, array $acc, User $by): void
    {
        $posting = app(PostingService::class);
        $items = $budget->items()->with('account')->get();
        $months = 0;
        for ($m = $year->start_date->copy()->startOfMonth(); $m->lt(today()->startOfMonth()); $m->addMonth()) {
            $months++;
            $date = $m->copy()->addDays(mt_rand(5, 24))->toDateString();
            foreach ($items as $item) {
                $monthly = (float) $item->revised_amount / 12;
                if ($item->account->type === 'revenue') {
                    $amount = round($monthly * mt_rand(85, 115) / 100, -3);
                    $posting->post($date, $item->description.' — '.$m->format('M Y'), [
                        ['account_id' => $acc[$item->account->code === '4110' ? '1210' : '1110'], 'debit' => $amount, 'department_id' => $item->department_id],
                        ['account_id' => $item->account_id, 'credit' => $amount, 'department_id' => $item->department_id, 'fund_source_id' => $item->fund_source_id,
                            'task_activity_id' => $item->task_activity_id, 'budget_item_id' => $item->id],
                    ], $by, 'manual');

                    continue;
                }
                if (mt_rand(1, 100) > 75) {
                    continue;
                }
                $amount = round($monthly * mt_rand(70, 120) / 100, -3);
                if ($amount <= 0) {
                    continue;
                }
                $posting->post($date, $item->description.' — '.$m->format('M Y'), [
                    ['account_id' => $item->account_id, 'debit' => $amount, 'department_id' => $item->department_id, 'fund_source_id' => $item->fund_source_id,
                        'task_activity_id' => $item->task_activity_id, 'budget_item_id' => $item->id, 'description' => $item->description],
                    ['account_id' => $acc[$amount < 500000 ? '1150' : '1100'], 'credit' => $amount],
                ], $by, 'manual');
            }
            $nhif = round((float) $items->where('account.code', '4110')->sum('revised_amount') / 12 * 0.8, -3);
            if ($nhif > 0) {
                $posting->post($m->copy()->endOfMonth()->toDateString(), 'NHIF claims paid — '.$m->format('M Y'), [
                    ['account_id' => $acc['1100'], 'debit' => $nhif], ['account_id' => $acc['1210'], 'credit' => $nhif],
                ], $by, 'manual');
            }
        }
        $this->line("Accounting: {$months} months of posted spending and revenue (".JournalEntry::where('status', 'posted')->count().' journals)');
    }

    private function tasks(array $activities, User $by): void
    {
        $created = 0;
        foreach ($activities as $a) {
            if (str_starts_with($a['spec'][1], '41')) {
                continue;
            }
            $employee = Employee::active()->where('department_id', $a['department']->id)->inRandomOrder()->first();
            if (! $employee) {
                continue;
            }
            $progress = [100, 80, 60, 40, 25, 0][mt_rand(0, 5)];
            Task::create(['task_activity_id' => $a['activity']->id, 'title' => $a['sub']->title, 'priority' => 'normal', 'employee_id' => $employee->id, 'department_id' => $a['department']->id,
                'created_by' => $by->id, 'start_date' => today()->subMonths(3), 'due_date' => today()->addMonth(), 'status' => $progress === 100 ? 'done' : ($progress ? 'in_progress' : 'todo'),
                'progress' => $progress, 'completed_at' => $progress === 100 ? now()->subDays(5) : null]);
            $created++;
        }
        $this->line("Tasks: {$created} tasks under the activities (progress feeds the plan)");
    }

    private function pending(Budget $budget, array $acc, User $by): void
    {
        $journal = app(ManageJournals::class)->save(['date' => today()->toDateString(), 'description' => 'Accrue electricity for the month', 'lines' => [
            ['account_id' => $acc['5300'], 'debit' => 4800000], ['account_id' => $acc['2100'], 'credit' => 4800000],
        ]], $by);
        app(ManageJournals::class)->submit($journal, $by);
        $items = $budget->items()->whereHas('account', fn ($q) => $q->where('type', 'expense'))->orderBy('id')->take(2)->get();
        if ($items->count() === 2) {
            app(ManageTransfers::class)->request($budget, ['kind' => 'transfer', 'from_item_id' => $items[0]->id, 'to_item_id' => $items[1]->id, 'amount' => 500000,
                'reason' => 'Demo: more needed for '.$items[1]->label()], $by);
        }
        $this->line('Waiting for approval: 1 manual journal'.($items->count() === 2 ? ', 1 budget transfer' : ''));
    }

    private function kind(string $department): string
    {
        $d = strtolower($department);

        return match (true) {
            str_contains($d, 'nurs') => 'nurs',
            str_contains($d, 'clinic') || str_contains($d, 'medical') || str_contains($d, 'lab') => 'clinic',
            str_contains($d, 'financ') || str_contains($d, 'account') => 'finance',
            str_contains($d, 'market') || str_contains($d, 'public') => 'market',
            default => 'generic',
        };
    }
}
