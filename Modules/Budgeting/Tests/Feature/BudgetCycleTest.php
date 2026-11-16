<?php

namespace Modules\Budgeting\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Accounting\Services\PostingService;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Services\BudgetService;
use Modules\Planning\Models\AnnualPlan;
use Modules\Planning\Models\SubActivity;
use Modules\Tasks\Models\TaskActivity;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class BudgetCycleTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private User $finance;

    private User $director;

    /** @var array<string, User> */
    private array $heads = [];

    private Department $pharmacy;

    private Department $lab;

    private array $acc = [];

    private int $activity;

    private int $sub;

    private int $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-18 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'accounting'], ['slug' => 'planning'], ['slug' => 'tasks'], ['slug' => 'budgeting']]]);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->run($this->company, function () {
            Role::findOrCreate('accountant', 'web')->syncPermissions(['accounting.view', 'accounting.journals', 'accounting.manage', 'budgeting.prepare', 'budgeting.view', 'budgeting.manage']);
            $this->finance = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('accountant');
            $this->director = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('director-general');
            $this->pharmacy = Department::create(['name' => 'Pharmacy', 'is_active' => true]);
            $this->lab = Department::create(['name' => 'Laboratory', 'is_active' => true]);
            foreach (['pharm' => $this->pharmacy, 'lab' => $this->lab] as $who => $dept) {
                $this->heads[$who] = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
                Employee::create(['employee_no' => strtoupper($who), 'fname' => ucfirst($who), 'lname' => 'X', 'gender' => 'Female', 'department_id' => $dept->id,
                    'user_id' => $this->heads[$who]->id, 'employment_status' => 'confirmed', 'date_of_birth' => '1990-11-15']);
            }
            $year = app(FiscalCalendar::class)->createYear('2026-01-01');
            $this->year = $year->id;
            foreach ([['1100', 'Bank', 'asset', false], ['5100', 'Medical supplies', 'expense', true], ['5200', 'Travel', 'expense', true]] as [$code, $name, $type, $b]) {
                $this->acc[$code] = Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'is_postable' => true, 'is_budgetable' => $b, 'is_active' => true])->id;
            }
            $plan = AnnualPlan::create(['title' => 'Annual plan 2026', 'year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
            $this->activity = TaskActivity::create(['department_id' => $this->pharmacy->id, 'annual_plan_id' => $plan->id, 'year' => 2026, 'code' => '1.1', 'title' => 'Stock management', 'is_active' => true])->id;
            $this->sub = SubActivity::create(['task_activity_id' => $this->activity, 'code' => '1.1.1', 'title' => 'Buy essential medicines'])->id;
            $accountant = Role::where('name', 'accountant')->value('id');
            foreach (['department-budget' => $accountant, 'budget-approval' => Role::where('name', 'director-general')->value('id'), 'budget-transfer' => $accountant] as $process => $role) {
                WorkflowTemplate::where('process', $process)->delete();
                WorkflowTemplate::create(['process' => $process, 'company_branch_id' => null, 'is_active' => true])
                    ->steps()->create(['step_order' => 1, 'name' => 'Approver', 'approver_type' => 'role', 'role_id' => $role, 'allow_send_back' => true]);
            }
        });
        $this->giveActiveContracts($this->company);
        $emp = fn ($w) => Employee::withoutGlobalScopes()->where('employee_no', strtoupper($w))->value('id');
        $this->actingAs($this->admin)->postJson("/api/v1/hr/structure/departments/{$this->pharmacy->id}/head", ['employee_id' => $emp('pharm')])->assertOk();
        $this->postJson("/api/v1/hr/structure/departments/{$this->lab->id}/head", ['employee_id' => $emp('lab')])->assertOk();
        foreach ($this->heads as $u) {
            $u->refresh();
        }
    }

    private function approve(string $process, User $as): void
    {
        $id = WorkflowInstance::where('process', $process)->where('status', 'pending')->latest('id')->value('id');
        $this->actingAs($as)->postJson("/api/v1/workflow/requests/{$id}/approve")->assertOk();
    }

    public function test_budget_is_prepared_approved_controlled_and_transferred(): void
    {
        $budget = $this->actingAs($this->finance)->postJson('/api/v1/budgeting/budgets', ['fiscal_year_id' => $this->year, 'title' => 'Budget 2026', 'annual_plan_id' => AnnualPlan::value('id')])
            ->assertCreated()->assertJsonPath('data.phasing', 'quarter')->json('data.id');
        $this->postJson('/api/v1/budgeting/budgets', ['fiscal_year_id' => $this->year, 'title' => 'Again'])->assertUnprocessable();
        $this->postJson("/api/v1/budgeting/budgets/{$budget}/open")->assertOk()->assertJsonPath('data.created', 3);
        $pharm = "/api/v1/budgeting/budgets/{$budget}/departments/{$this->pharmacy->id}";

        $this->actingAs($this->heads['lab'])->postJson("{$pharm}/items", ['account_id' => $this->acc['5100'], 'quantity' => 1, 'unit_cost' => 1])->assertForbidden();
        $this->actingAs($this->heads['pharm'])->getJson($pharm)->assertOk()->assertJsonPath('activities.0.sub_activities.0.title', 'Buy essential medicines')
            ->assertJsonPath('budget.period_labels.3', 'Q4')->assertJsonPath('can.edit', true)->assertJsonCount(2, 'accounts');
        $this->postJson("{$pharm}/items", ['account_id' => $this->acc['1100'], 'quantity' => 1, 'unit_cost' => 10])->assertUnprocessable()->assertJsonValidationErrors(['account_id']);
        $this->postJson("{$pharm}/items", ['account_id' => $this->acc['5100'], 'quantity' => 10, 'unit_cost' => 50000, 'periods' => [100, 100, 100, 100]])
            ->assertUnprocessable()->assertJsonValidationErrors(['periods' => 'add up']);
        $a = $this->postJson("{$pharm}/items", ['task_activity_id' => $this->activity, 'sub_activity_id' => $this->sub, 'account_id' => $this->acc['5100'], 'quantity' => 10, 'unit' => 'cartons', 'unit_cost' => 50000])
            ->assertCreated()->json('data.id');
        $b = $this->postJson("{$pharm}/items", ['task_activity_id' => $this->activity, 'account_id' => $this->acc['5200'], 'quantity' => 2, 'unit_cost' => 25000, 'frequency' => 2, 'periods' => [0, 50000, 0, 50000]])
            ->assertCreated()->json('data.id');
        $this->getJson($pharm)->assertJsonPath('totals.original', 600000)->assertJsonPath('items.0.periods.0', 125000)->assertJsonPath('items.1.periods.1', 50000);

        $this->actingAs($this->finance)->putJson("{$pharm}/ceiling", ['ceiling' => 400000])->assertOk();
        $this->actingAs($this->heads['pharm'])->postJson("{$pharm}/submit")->assertUnprocessable()->assertJsonValidationErrors(['budget' => 'ceiling']);
        $this->actingAs($this->finance)->putJson("{$pharm}/ceiling", ['ceiling' => null])->assertOk();
        $this->actingAs($this->heads['pharm'])->postJson("{$pharm}/submit")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson("{$pharm}/items", ['account_id' => $this->acc['5100'], 'quantity' => 1, 'unit_cost' => 1])->assertUnprocessable();
        $this->approve('department-budget', $this->finance);

        $this->actingAs($this->finance)->postJson("/api/v1/budgeting/budgets/{$budget}/submit")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->approve('budget-approval', $this->director);
        $this->actingAs($this->finance)->getJson("/api/v1/budgeting/budgets/{$budget}")->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('totals.revised', 600000)->assertJsonPath('departments.2.name', 'Pharmacy');

        WorkflowTemplate::where('process', 'budget-release')->delete();
        $this->getJson("/api/v1/budgeting/items/{$a}")->assertJsonPath('data.available', 0)->assertJsonPath('data.unreleased', 500000);
        $this->postJson("/api/v1/budgeting/budgets/{$budget}/releases", ['period' => 1])->assertCreated()->assertJsonPath('data.status', 'opened');
        $this->postJson("/api/v1/budgeting/budgets/{$budget}/releases", ['period' => 1])->assertUnprocessable();
        $this->postJson("/api/v1/budgeting/budgets/{$budget}/releases", ['period' => 2])->assertCreated();
        $this->getJson("/api/v1/budgeting/budgets/{$budget}")->assertJsonPath('periods.0.status', 'opened')->assertJsonPath('periods.2.status', 'closed')
            ->assertJsonPath('periods.1.amount', 175000)->assertJsonPath('totals.released', 300000);

        app(CompanyContext::class)->run($this->company, function () use ($a) {
            app(PostingService::class)->post('2026-11-10', 'Medicines bought', [
                ['account_id' => $this->acc['5100'], 'debit' => 120000, 'department_id' => $this->pharmacy->id, 'budget_item_id' => $a],
                ['account_id' => $this->acc['1100'], 'credit' => 120000],
            ], $this->finance, 'manual');
            $svc = app(BudgetService::class);
            $this->assertSame(130000.0, $svc->available(BudgetItem::find($a)));
            $short = $svc->reserve($this->director, [$a => 200000], true);
            $this->assertSame(130000.0, $short[0]['available']);
            $svc->release($this->director);
            try {
                $svc->reserve($this->director, [$a => 200000]);
                $this->fail('An over-budget hold must be refused.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('available 130,000.00', $e->getMessage());
            }
            $svc->reserve($this->director, [$a => 30000]);
            $this->assertSame(100000.0, $svc->available(BudgetItem::find($a)));
            $this->assertTrue($svc->check(BudgetItem::find($a), 400000)['over']);
        });

        $this->getJson("/api/v1/budgeting/budgets/{$budget}/vs-actual?group=item")->assertOk()->assertJsonPath('totals.actual', 120000)->assertJsonPath('totals.reserved', 30000)
            ->assertJsonPath('totals.available', 150000);
        $this->getJson("/api/v1/budgeting/budgets/{$budget}/vs-actual?group=sub_activity")->assertJsonPath('data.0.label', '1.1.1 Buy essential medicines')->assertJsonPath('data.0.used_percent', 30);
        $this->getJson("/api/v1/budgeting/items/{$a}")->assertJsonCount(1, 'lines')->assertJsonPath('data.available', 100000)->assertJsonPath('entries.0.balance', 'reserved');
        $this->actingAs($this->heads['lab'])->getJson("/api/v1/budgeting/budgets/{$budget}/vs-actual")->assertJsonCount(0, 'data');

        $this->actingAs($this->heads['pharm'])->postJson("/api/v1/budgeting/budgets/{$budget}/transfers", ['kind' => 'transfer', 'from_item_id' => $a, 'to_item_id' => $b, 'amount' => 400000, 'reason' => 'Fuel'])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount' => 'Only 350,000.00']);
        $this->postJson("/api/v1/budgeting/budgets/{$budget}/transfers", ['kind' => 'supplementary', 'to_item_id' => $b, 'amount' => 1000, 'reason' => 'More'])->assertForbidden();
        $this->postJson("/api/v1/budgeting/budgets/{$budget}/transfers", ['kind' => 'transfer', 'from_item_id' => $a, 'to_item_id' => $b, 'amount' => 50000, 'reason' => 'Outreach trips'])->assertCreated();
        $this->getJson("/api/v1/budgeting/items/{$a}")->assertJsonPath('data.available', 50000);
        $this->approve('budget-transfer', $this->finance);
        $this->getJson("/api/v1/budgeting/items/{$a}")->assertJsonPath('data.revised', 450000)->assertJsonPath('data.original', 500000)->assertJsonPath('data.available', 100000);
        $this->getJson("/api/v1/budgeting/items/{$b}")->assertJsonPath('data.revised', 150000)->assertJsonPath('data.released', 100000);
        $this->getJson("/api/v1/budgeting/budgets/{$budget}/transfers")->assertJsonPath('data.0.status', 'approved');
        $this->artisan('budget:check-balances')->assertSuccessful();

        $this->actingAs($this->finance)->putJson('/api/v1/accounting/settings', ['use_gfs' => false, 'budget_phasing' => 'quarter', 'budget_control' => 'warn', 'budget_release' => 'none'])->assertOk();
        $this->getJson("/api/v1/budgeting/items/{$b}")->assertJsonPath('data.available', 150000);
        $this->actingAs($this->heads['pharm'])->getJson('/api/v1/budgeting/items?department_id='.$this->pharmacy->id)->assertJsonCount(2, 'data')->assertJsonPath('budget.title', 'Budget 2026');
    }
}
