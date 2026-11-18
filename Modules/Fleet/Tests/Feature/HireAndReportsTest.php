<?php

namespace Modules\Fleet\Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Services\BudgetService;
use Modules\Fleet\Actions\ManageHires;
use Modules\Fleet\Models\FleetUnit;
use Modules\Fleet\Models\FuelStore;
use Modules\Procurement\Models\Supplier;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class HireAndReportsTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $officer;

    private int $dept;

    private int $item;

    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-18 09:00:00');
        [$this->company] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'fleet'], ['slug' => 'accounting'], ['slug' => 'budgeting'], ['slug' => 'payments'], ['slug' => 'procurement']]]);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->run($this->company, function () {
            Role::findOrCreate('transport-officer', 'web')->syncPermissions(['fleet.view', 'fleet.manage', 'payments.request']);
            $this->officer = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('transport-officer');
            $this->dept = Department::create(['name' => 'Transport', 'is_active' => true])->id;
            $this->supplier = Supplier::create(['code' => 'S1', 'name' => 'Safari Car Hire', 'kind' => 'company', 'is_active' => true])->id;
            $year = app(FiscalCalendar::class)->createYear('2026-01-01');
            $acc = Account::create(['code' => '5600', 'name' => 'Vehicle hire', 'type' => 'expense', 'is_postable' => true, 'is_budgetable' => true, 'is_active' => true]);
            AccountingSetting::current()->fill(['budget_release' => 'none', 'budget_control' => 'warn'])->save();
            $budget = Budget::create(['fiscal_year_id' => $year->id, 'title' => 'Budget 2026', 'phasing' => 'quarter', 'status' => 'approved', 'created_by' => $this->officer->id]);
            $this->item = BudgetItem::create(['budget_id' => $budget->id, 'department_id' => $this->dept, 'account_id' => $acc->id, 'description' => 'Hire', 'quantity' => 1, 'unit_cost' => 50000000,
                'original_amount' => 50000000, 'revised_amount' => 50000000])->id;
            app(BudgetService::class)->record($this->item, 'approved', 50000000, $budget, 'Approved');
            FuelStore::create(['name' => 'Workshop tank', 'kind' => 'own', 'fuel_type' => 'diesel']);
        });
    }

    public function test_hired_vehicles_join_the_fleet_are_paid_by_period_and_leave_when_the_hire_ends(): void
    {
        $id = $this->actingAs($this->officer)->postJson('/api/v1/fleet/hires', ['supplier_id' => $this->supplier, 'reference' => 'HC-1', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30',
            'rate_mode' => 'day', 'rate' => 100000, 'vehicles' => [['registration' => 'T 900 HHH', 'name' => 'Noah', 'seats' => 7], ['registration' => 'T 901 HHH', 'name' => 'Noah 2', 'seats' => 7]]])
            ->assertCreated()->assertJsonPath('data.number', 'HIR-2026-00001')->json('data.id');
        $this->getJson('/api/v1/fleet/units?search=HHH')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.ownership', 'hired')->assertJsonPath('data.0.status', 'operational');

        $p = $this->postJson("/api/v1/fleet/hires/{$id}/pay", ['from_date' => '2026-11-01', 'to_date' => '2026-11-10', 'department_id' => $this->dept, 'budget_item_id' => $this->item])
            ->assertCreated()->assertJsonPath('data.amount', 2000000)->json('data');
        $this->getJson("/api/v1/payments/requests/{$p['payment_request_id']}")->assertJsonPath('data.payees.0.name', 'Safari Car Hire');
        $this->postJson("/api/v1/fleet/hires/{$id}/pay", ['from_date' => '2026-11-05', 'to_date' => '2026-11-15', 'department_id' => $this->dept, 'budget_item_id' => $this->item])
            ->assertUnprocessable()->assertJsonValidationErrors(['from_date' => 'already paid']);
        $this->getJson('/api/v1/fleet/hires')->assertJsonPath('data.0.paid_until', '2026-11-10')->assertJsonPath('data.0.paid', 2000000);

        $this->postJson("/api/v1/fleet/hires/{$id}/end", ['date' => '2026-11-20', 'note' => 'Project finished early'])->assertOk();
        $this->getJson('/api/v1/fleet/units?search=HHH')->assertJsonPath('meta.total', 0);
        $this->postJson("/api/v1/fleet/hires/{$id}/pay", ['from_date' => '2026-11-11', 'to_date' => '2026-11-30', 'department_id' => $this->dept, 'budget_item_id' => $this->item])
            ->assertCreated()->assertJsonPath('data.amount', 2000000);

        $other = $this->postJson('/api/v1/fleet/hires', ['supplier_id' => $this->supplier, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-15', 'rate_mode' => 'month', 'rate' => 3000000,
            'vehicles' => [['registration' => 'T 902 HHH', 'name' => 'Pickup']]])->json('data.id');
        $this->assertSame(1, app(CompanyContext::class)->run($this->company, fn () => app(ManageHires::class)->endDue()));
        $this->assertSame('disposed', app(CompanyContext::class)->run($this->company, fn () => FleetUnit::where('registration', 'T 902 HHH')->value('status')));
        $this->assertNotNull($other);
    }

    public function test_every_fleet_report_and_the_dashboard_run(): void
    {
        $this->actingAs($this->officer)->postJson('/api/v1/fleet/units', ['kind' => 'vehicle', 'registration' => 'T 1 A', 'name' => 'Pickup', 'ownership' => 'owned', 'reading' => 1000])->assertCreated();
        $catalog = collect($this->getJson('/api/v1/reports/finance')->assertOk()->json('data'))->where('group', 'Fleet');
        $this->assertCount(10, $catalog);
        foreach ($catalog as $r) {
            $this->getJson("/api/v1/reports/finance/{$r['key']}?from=2026-11-01&to=2026-11-30")->assertOk()->assertJsonStructure(['data' => ['title', 'columns', 'rows']]);
        }
        $this->getJson('/api/v1/reports/finance/fleet-register')->assertJsonPath('data.rows.0.registration', 'T 1 A');
        $this->getJson('/api/v1/fleet/dashboard')->assertOk()->assertJsonPath('data.units', 1)->assertJsonPath('data.active_hires', 0);
    }
}
