<?php

namespace Modules\Fleet\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Services\BudgetService;
use Modules\Fleet\Models\Assignment;
use Modules\Fleet\Models\Driver;
use Modules\Fleet\Models\FleetUnit;
use Modules\Procurement\Models\Supplier;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class FuelTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $officer;

    private User $attendant;

    private User $approver;

    private User $driverUser;

    private int $unit;

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
            Role::findOrCreate('pump', 'web')->syncPermissions(['fleet.fuel']);
            Role::findOrCreate('fuel-approver', 'web')->syncPermissions(['fleet.view']);
            $this->officer = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('transport-officer');
            $this->attendant = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('pump');
            $this->approver = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('fuel-approver');
            $this->driverUser = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
            $this->dept = Department::create(['name' => 'Transport', 'is_active' => true])->id;
            $emp = Employee::create(['employee_no' => 'D1', 'fname' => 'Juma', 'lname' => 'X', 'gender' => 'Male', 'department_id' => $this->dept, 'user_id' => $this->driverUser->id,
                'employment_status' => 'confirmed', 'date_of_birth' => '1985-01-01'])->id;
            $this->unit = FleetUnit::create(['kind' => 'vehicle', 'registration' => 'T 100 AAA', 'name' => 'Hilux', 'ownership' => 'owned', 'status' => 'operational', 'fuel_type' => 'diesel',
                'tank_capacity' => 80, 'reading' => 10000, 'department_id' => $this->dept])->id;
            $driver = Driver::create(['employee_id' => $emp, 'status' => 'active']);
            Assignment::create(['fleet_unit_id' => $this->unit, 'fleet_driver_id' => $driver->id, 'starts_on' => '2026-11-01']);
            $this->supplier = Supplier::create(['code' => 'S1', 'name' => 'Puma Energy', 'kind' => 'company', 'is_active' => true])->id;
            $year = app(FiscalCalendar::class)->createYear('2026-01-01');
            $acc = Account::create(['code' => '5400', 'name' => 'Fuel', 'type' => 'expense', 'is_postable' => true, 'is_budgetable' => true, 'is_active' => true]);
            AccountingSetting::current()->fill(['budget_release' => 'none', 'budget_control' => 'warn'])->save();
            $budget = Budget::create(['fiscal_year_id' => $year->id, 'title' => 'Budget 2026', 'phasing' => 'quarter', 'status' => 'approved', 'created_by' => $this->officer->id]);
            $this->item = BudgetItem::create(['budget_id' => $budget->id, 'department_id' => $this->dept, 'account_id' => $acc->id, 'description' => 'Fuel', 'quantity' => 1, 'unit_cost' => 20000000,
                'original_amount' => 20000000, 'revised_amount' => 20000000])->id;
            app(BudgetService::class)->record($this->item, 'approved', 20000000, $budget, 'Approved');
            $this->assertTrue(WorkflowTemplate::where('process', 'fuel-request')->exists());
            WorkflowTemplate::where('process', 'fuel-request')->delete();
            $t = WorkflowTemplate::create(['process' => 'fuel-request', 'company_branch_id' => null, 'is_active' => true]);
            $t->steps()->create(['step_order' => 1, 'name' => 'Transport', 'approver_type' => 'role', 'role_id' => Role::where('name', 'fuel-approver')->value('id'), 'allow_send_back' => true]);
        });
        $this->giveActiveContracts($this->company);
    }

    private function approve(): void
    {
        $wf = WorkflowInstance::where('process', 'fuel-request')->where('status', 'pending')->latest('id')->value('id');
        $this->actingAs($this->approver)->postJson("/api/v1/workflow/requests/{$wf}/approve")->assertOk();
    }

    private function fill(int $tank, float $litres, float $reading, float $issued): int
    {
        $id = $this->actingAs($this->driverUser)->postJson('/api/v1/fleet/fuel', ['fleet_unit_id' => $this->unit, 'fleet_fuel_store_id' => $tank, 'litres' => $litres, 'reading' => $reading])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->approve();
        $this->actingAs($this->attendant)->postJson("/api/v1/fleet/fuel/{$id}/issue", ['litres' => $issued, 'reading' => $reading])->assertOk();

        return $id;
    }

    public function test_tank_deliveries_requests_checks_and_issues(): void
    {
        $tank = $this->actingAs($this->officer)->postJson('/api/v1/fleet/fuel-stores', ['name' => 'Workshop tank', 'kind' => 'own', 'fuel_type' => 'diesel', 'capacity' => 5000, 'reorder_level' => 500])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/fleet/fuel-stores/{$tank}/recharge", ['supplier_id' => $this->supplier, 'date' => '2026-11-18', 'litres' => 6000, 'unit_price' => 3000, 'department_id' => $this->dept,
            'budget_item_id' => $this->item])->assertUnprocessable()->assertJsonValidationErrors(['litres']);
        $rc = $this->postJson("/api/v1/fleet/fuel-stores/{$tank}/recharge", ['supplier_id' => $this->supplier, 'date' => '2026-11-18', 'litres' => 2000, 'unit_price' => 3000,
            'department_id' => $this->dept, 'budget_item_id' => $this->item])->assertCreated()->json('data');
        $this->getJson("/api/v1/payments/requests/{$rc['payment_request_id']}")->assertJsonPath('data.total', 6000000)->assertJsonPath('data.payees.0.name', 'Puma Energy');
        $this->actingAs($this->attendant)->postJson("/api/v1/fleet/fuel-recharges/{$rc['id']}/receive", ['litres' => 1990, 'date' => '2026-11-18', 'delivery_note' => 'DN-55'])->assertOk();

        $form = $this->actingAs($this->driverUser)->getJson('/api/v1/fleet/fuel/form')->assertOk()->json('data');
        $this->assertSame([$this->unit], array_column($form['units'], 'id'));
        $this->assertSame(1990.0, (float) $form['stores'][0]['free']);
        $this->postJson('/api/v1/fleet/fuel', ['fleet_unit_id' => $this->unit, 'fleet_fuel_store_id' => $tank, 'litres' => 90, 'reading' => 10050])
            ->assertUnprocessable()->assertJsonValidationErrors(['litres' => 'more than the 80 L tank']);

        $first = $this->actingAs($this->driverUser)->postJson('/api/v1/fleet/fuel', ['fleet_unit_id' => $this->unit, 'fleet_fuel_store_id' => $tank, 'litres' => 70, 'reading' => 10100])->json('data.id');
        $this->postJson('/api/v1/fleet/fuel', ['fleet_unit_id' => $this->unit, 'fleet_fuel_store_id' => $tank, 'litres' => 10, 'reading' => 10100])->assertUnprocessable()->assertJsonValidationErrors(['litres' => 'still open']);
        $this->approve();
        $this->actingAs($this->driverUser)->getJson("/api/v1/fleet/fuel/{$first}")->assertJsonPath('data.can.issue', false)->assertJsonPath('data.status', 'approved');
        $this->actingAs($this->attendant)->postJson("/api/v1/fleet/fuel/{$first}/issue", ['litres' => 75, 'reading' => 10100])->assertUnprocessable()->assertJsonValidationErrors(['litres']);
        $this->postJson("/api/v1/fleet/fuel/{$first}/issue", ['litres' => 65, 'reading' => 10100])->assertOk();
        $this->getJson("/api/v1/fleet/fuel/{$first}")->assertJsonPath('data.amount', 195000)->assertJsonPath('data.issued_by', $this->attendant->display_name);

        $this->fill($tank, 60, 10600, 60);
        $this->actingAs($this->officer)->getJson("/api/v1/fleet/units/{$this->unit}")->assertJsonPath('data.reading', 10600)->assertJsonPath('data.cost_total', 375000);

        $check = $this->actingAs($this->driverUser)->postJson('/api/v1/fleet/fuel/check', ['fleet_unit_id' => $this->unit, 'fleet_fuel_store_id' => $tank, 'litres' => 70, 'reading' => 10700])->json('data');
        $this->assertSame([], $check['hard']);
        $this->assertStringContainsString('100 km since the last fill', $check['soft'][0]);
        $flagged = $this->postJson('/api/v1/fleet/fuel', ['fleet_unit_id' => $this->unit, 'fleet_fuel_store_id' => $tank, 'litres' => 70, 'reading' => 10700])->json('data.id');
        $this->actingAs($this->officer)->getJson('/api/v1/fleet/fuel')->assertJsonPath('data.0.id', $flagged)->assertJsonPath('data.0.warnings', 1);
        $this->postJson("/api/v1/fleet/fuel-stores/{$tank}/adjust", ['counted' => 1850, 'date' => '2026-11-18', 'reason' => 'Monthly dip'])->assertOk();
        $this->getJson("/api/v1/fleet/fuel-stores/{$tank}/movements")->assertJsonPath('balance', 1850)->assertJsonPath('data.0.litres', -15);

        $rows = $this->getJson('/api/v1/fleet/fuel/consumption?from=2026-11-01&to=2026-11-30')->json('data');
        $this->assertSame(125.0, (float) $rows[0]['litres']);
        $this->assertSame(600.0, (float) $rows[0]['distance']);
    }

    public function test_station_account_fills_are_paid_in_one_batch(): void
    {
        $station = $this->actingAs($this->officer)->postJson('/api/v1/fleet/fuel-stores', ['name' => 'Puma Mwenge', 'kind' => 'station', 'fuel_type' => 'diesel'])
            ->assertUnprocessable()->assertJsonValidationErrors(['supplier_id']);
        $station = $this->postJson('/api/v1/fleet/fuel-stores', ['name' => 'Puma Mwenge', 'kind' => 'station', 'fuel_type' => 'diesel', 'supplier_id' => $this->supplier, 'price' => 3100])->json('data.id');
        $id = $this->actingAs($this->driverUser)->postJson('/api/v1/fleet/fuel', ['fleet_unit_id' => $this->unit, 'fleet_fuel_store_id' => $station, 'litres' => 50, 'reading' => 10200])->json('data.id');
        $this->approve();
        $this->actingAs($this->officer)->postJson("/api/v1/fleet/fuel/{$id}/issue", ['litres' => 50, 'reading' => 10200])->assertUnprocessable()->assertJsonValidationErrors(['receipt_reference']);
        $this->postJson("/api/v1/fleet/fuel/{$id}/issue", ['litres' => 50, 'reading' => 10200, 'unit_price' => 3150, 'receipt_reference' => 'RCPT-9'])->assertOk();
        $this->getJson('/api/v1/fleet/fuel-stores')->assertJsonPath('data.0.unpaid', 157500);
        $b = $this->postJson("/api/v1/fleet/fuel-stores/{$station}/batch", ['from_date' => '2026-11-01', 'to_date' => '2026-11-30', 'department_id' => $this->dept, 'budget_item_id' => $this->item])
            ->assertCreated()->assertJsonPath('data.number', 'FB-2026-00001')->json('data');
        $this->getJson("/api/v1/payments/requests/{$b['payment_request_id']}")->assertJsonPath('data.total', 157500);
        $this->getJson('/api/v1/fleet/fuel-stores')->assertJsonPath('data.0.unpaid', 0)->assertJsonPath('batches.0.litres', 50);
    }
}
