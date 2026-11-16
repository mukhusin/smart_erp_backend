<?php

namespace Modules\Reporting\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\FundSource;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Accounting\Services\PostingService;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Services\BudgetService;
use Modules\Planning\Models\AnnualPlan;
use Modules\Tasks\Models\Task;
use Modules\Tasks\Models\TaskActivity;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class FinanceReportsTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_every_finance_report_runs_and_statements_balance(): void
    {
        $this->travelTo('2026-11-18 09:00:00');
        $modules = array_map(fn ($s) => ['slug' => $s], ['hr', 'admin', 'accounting', 'budgeting', 'planning', 'tasks', 'payments', 'receivables', 'payroll', 'loans']);
        [$company, $admin] = $this->createCompany('Alpha', ['modules' => $modules]);
        setPermissionsTeamId($company->id);
        $ids = app(CompanyContext::class)->run($company, function () {
            Role::findOrCreate('finance', 'web')->syncPermissions(['accounting.view', 'budgeting.view', 'planning.view', 'payments.view', 'receivables.view', 'payroll.view', 'loans.view']);
            $user = tap(User::factory()->create(['company_id' => app(CompanyContext::class)->id()]))->assignRole('finance');
            $dept = Department::create(['name' => 'Pharmacy', 'is_active' => true])->id;
            $year = app(FiscalCalendar::class)->createYear('2026-01-01');
            $acc = [];
            foreach ([['1100', 'Bank', 'asset'], ['2100', 'Payables', 'liability'], ['3000', 'Fund balance', 'equity'], ['4100', 'Patient fees', 'revenue'], ['5100', 'Medicines', 'expense']] as [$c, $n, $t]) {
                $acc[$c] = Account::create(['code' => $c, 'name' => $n, 'type' => $t, 'is_postable' => true, 'is_budgetable' => in_array($t, ['revenue', 'expense'], true), 'is_active' => true])->id;
            }
            $bank = CashBankAccount::create(['name' => 'Main bank', 'kind' => 'bank', 'account_id' => $acc['1100'], 'currency' => 'TZS', 'is_active' => true])->id;
            $fund = FundSource::create(['code' => 'GRANT', 'name' => 'Donor grant', 'kind' => 'donor', 'is_active' => true])->id;
            $plan = AnnualPlan::create(['title' => 'Plan 2026', 'year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
            $activity = TaskActivity::create(['department_id' => $dept, 'annual_plan_id' => $plan->id, 'year' => 2026, 'code' => '1.1', 'title' => 'Stock medicines', 'quarters' => '1,2', 'is_active' => true]);
            $emp = Employee::create(['employee_no' => 'E1', 'fname' => 'Asha', 'lname' => 'M', 'gender' => 'Female', 'department_id' => $dept, 'employment_status' => 'confirmed', 'date_of_birth' => '1990-01-01']);
            Task::create(['task_activity_id' => $activity->id, 'title' => 'Order', 'department_id' => $dept, 'employee_id' => $emp->id, 'created_by' => $user->id, 'status' => 'done', 'progress' => 100, 'priority' => 'normal']);
            $budget = Budget::create(['fiscal_year_id' => $year->id, 'title' => 'Budget 2026', 'phasing' => 'quarter', 'status' => 'approved', 'created_by' => $user->id]);
            $item = BudgetItem::create(['budget_id' => $budget->id, 'department_id' => $dept, 'task_activity_id' => $activity->id, 'account_id' => $acc['5100'], 'fund_source_id' => $fund,
                'quantity' => 1, 'unit_cost' => 400000, 'original_amount' => 400000, 'revised_amount' => 400000]);
            app(BudgetService::class)->record($item->id, 'approved', 400000, $budget, 'Approved');
            $post = app(PostingService::class);
            $post->post('2026-01-01', 'Opening', [['account_id' => $acc['1100'], 'debit' => 1000000], ['account_id' => $acc['3000'], 'credit' => 1000000]], $user, 'opening');
            $post->post('2026-03-10', 'Fees', [['account_id' => $acc['1100'], 'debit' => 500000], ['account_id' => $acc['4100'], 'credit' => 500000]], $user, 'manual');
            $post->post('2026-05-10', 'Medicines', [['account_id' => $acc['5100'], 'debit' => 150000, 'department_id' => $dept, 'fund_source_id' => $fund, 'budget_item_id' => $item->id],
                ['account_id' => $acc['1100'], 'credit' => 150000]], $user, 'manual');

            return ['user' => $user, 'bank' => $bank, 'budget' => $budget->id, 'plan' => $plan->id];
        });

        $catalog = $this->actingAs($ids['user'])->getJson('/api/v1/reports/finance')->assertOk()->json('data');
        $this->assertContains('income-statement', array_column($catalog, 'key'));
        $this->assertContains('loans-outstanding', array_column($catalog, 'key'));
        foreach ($catalog as $r) {
            $this->getJson("/api/v1/reports/finance/{$r['key']}")->assertOk();
        }

        $income = collect($this->getJson('/api/v1/reports/finance/income-statement')->json('data.rows'))->keyBy('account');
        $this->assertSame(350000, $income['Surplus / (deficit) for the period']['amount']);
        $position = collect($this->getJson('/api/v1/reports/finance/financial-position')->json('data.rows'))->keyBy('account');
        $this->assertSame(1350000, $position['Total Assets']['amount']);
        $this->assertSame(1350000, $position['Total liabilities and fund balances']['amount']);
        $this->getJson("/api/v1/reports/finance/cash-book?bank_id={$ids['bank']}&from=2026-03-01&to=2026-12-31")->assertJsonPath('data.rows.0.balance', 1000000)->assertJsonPath('data.rows.3.balance', 1350000);
        $this->getJson('/api/v1/reports/finance/fund-statement')->assertJsonPath('data.rows.0.fund', 'GRANT Donor grant')->assertJsonPath('data.rows.0.expense', 150000);
        $this->getJson("/api/v1/reports/finance/budget-vs-actual?budget_id={$ids['budget']}&group=activity")->assertJsonPath('data.rows.0.group', '1.1 Stock medicines')->assertJsonPath('data.rows.0.actual', 150000);
        $this->getJson("/api/v1/reports/finance/budget-quarterly?budget_id={$ids['budget']}")->assertJsonPath('data.rows.1.period', 'Q2')->assertJsonPath('data.rows.1.actual', 150000);
        $this->getJson("/api/v1/reports/finance/plan-performance?plan_id={$ids['plan']}")->assertJsonPath('data.rows.0.progress', 100)->assertJsonPath('data.rows.0.spent', 150000);
        $this->getJson("/api/v1/reports/finance/plan-activities?plan_id={$ids['plan']}")->assertJsonPath('data.rows.0.quarters', 'Q1, Q2');
        $this->get('/api/v1/reports/finance/trial-balance/csv')->assertOk();

        $this->actingAs($admin);
        Role::findOrCreate('nobody', 'web');
        $other = tap(User::factory()->create(['company_id' => $company->id]))->assignRole('employee');
        $this->actingAs($other)->getJson('/api/v1/reports/finance')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/reports/finance/income-statement')->assertNotFound();
    }
}
