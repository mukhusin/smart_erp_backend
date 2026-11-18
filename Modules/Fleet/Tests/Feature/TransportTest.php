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
use Modules\Fleet\Models\Driver;
use Modules\Fleet\Models\FleetUnit;
use Modules\Procurement\Models\Supplier;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class TransportTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $officer;

    private User $staff;

    private User $head;

    private User $driverUser;

    private int $unit;

    private int $driver;

    private int $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-18 09:00:00');
        [$this->company, $admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'fleet'], ['slug' => 'accounting'], ['slug' => 'budgeting'], ['slug' => 'payments'], ['slug' => 'procurement']]]);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->run($this->company, function () {
            Role::findOrCreate('transport-officer', 'web')->syncPermissions(['fleet.view', 'fleet.manage', 'payments.request']);
            $this->officer = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('transport-officer');
            $this->staff = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
            $this->head = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
            $this->driverUser = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
            $this->dept = Department::create(['name' => 'Outreach', 'is_active' => true])->id;
            $mk = fn ($no, $f, $user) => Employee::create(['employee_no' => $no, 'fname' => $f, 'lname' => 'X', 'gender' => 'Male', 'department_id' => $this->dept, 'user_id' => $user?->id,
                'employment_status' => 'confirmed', 'date_of_birth' => '1985-01-01'])->id;
            $mk('S1', 'Staff', $this->staff);
            $mk('H1', 'Head', $this->head);
            $driverEmp = $mk('D1', 'Juma', $this->driverUser);
            $this->unit = FleetUnit::create(['kind' => 'vehicle', 'registration' => 'T 100 AAA', 'name' => 'Hilux', 'ownership' => 'owned', 'status' => 'operational', 'seats' => 4, 'reading' => 10000])->id;
            $d = Driver::create(['employee_id' => $driverEmp, 'status' => 'active']);
            $type = \App\Models\ProfessionalLicence::create(['name' => 'Driving Licence', 'is_driving' => true, 'is_active' => true]);
            \App\Models\EmployeeLicence::create(['employee_id' => $driverEmp, 'professional_licence_id' => $type->id, 'licence_no' => 'DL', 'classes' => ['B'], 'start_date' => '2023-01-01', 'end_date' => '2028-01-01']);
            $this->driver = $d->id;
        });
        $this->giveActiveContracts($this->company);
        $head = Employee::withoutGlobalScopes()->where('employee_no', 'H1')->value('id');
        $this->actingAs($admin)->postJson("/api/v1/hr/structure/departments/{$this->dept}/head", ['employee_id' => $head])->assertOk();
    }

    public function test_request_dispatch_logbook_and_confirm(): void
    {
        $body = ['purpose' => 'Community outreach', 'destination' => 'Bagamoyo', 'departs_at' => '2026-11-20 08:00', 'returns_at' => '2026-11-20 17:00', 'passengers' => 5, 'cargo' => 'Vaccine cool boxes'];
        $id = $this->actingAs($this->staff)->postJson('/api/v1/fleet/requests', $body)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->actingAs($this->head->refresh());
        $wf = WorkflowInstance::where('process', 'transport-request')->where('status', 'pending')->value('id');
        $this->postJson("/api/v1/workflow/requests/{$wf}/approve")->assertOk();
        $this->assertSame(1, $this->officer->notifications()->where('data->title', 'Transport to allocate')->count());

        $this->actingAs($this->officer)->getJson('/api/v1/fleet/availability?departs_at=2026-11-20 08:00&returns_at=2026-11-20 17:00')
            ->assertJsonPath('data.vehicles.0.free', true)->assertJsonPath('data.drivers.0.free', true);
        $trip = ['transport_request_id' => $id, 'fleet_unit_id' => $this->unit, 'fleet_driver_id' => $this->driver] + $body;
        $this->postJson('/api/v1/fleet/trips/check', $trip)->assertJsonPath('data.hard', [])->assertJsonCount(2, 'data.soft');
        $this->postJson('/api/v1/fleet/trips', $trip)->assertUnprocessable()->assertJsonValidationErrors(['override_reason' => '4 seats for 5 passengers']);
        $tid = $this->postJson('/api/v1/fleet/trips', $trip + ['override_reason' => 'One child on lap; insurance renewal in progress'])->assertCreated()->assertJsonPath('data.number', 'TRP-2026-00001')->json('data.id');
        $this->getJson("/api/v1/fleet/requests/{$id}")->assertJsonPath('data.status', 'allocated')->assertJsonPath('data.trips.0.vehicle', 'T 100 AAA Hilux');
        $this->assertSame(1, $this->driverUser->notifications()->where('data->title', 'New trip')->count());

        $clash = ['purpose' => 'Other', 'destination' => 'Kibaha', 'departs_at' => '2026-11-20 15:00', 'returns_at' => '2026-11-20 19:00', 'fleet_unit_id' => $this->unit, 'fleet_driver_id' => $this->driver, 'override_reason' => 'x'];
        $this->postJson('/api/v1/fleet/trips', $clash)->assertUnprocessable()->assertJsonValidationErrors(['trip' => 'already booked']);

        $this->actingAs($this->driverUser)->getJson('/api/v1/self/fleet/trips')->assertJsonPath('data.0.number', 'TRP-2026-00001');
        $this->getJson("/api/v1/fleet/trips/{$tid}")->assertJsonPath('data.can.start', true)->assertJsonPath('data.can.confirm', false);
        $this->postJson("/api/v1/fleet/trips/{$tid}/start", ['reading' => 10020, 'started_at' => '2026-11-20 08:10'])->assertUnprocessable()->assertJsonValidationErrors(['started_at']);
        $this->travelTo('2026-11-20 08:12:00');
        $this->postJson("/api/v1/fleet/trips/{$tid}/start", ['reading' => 9000, 'started_at' => '2026-11-20 08:10'])->assertUnprocessable()->assertJsonValidationErrors(['reading']);
        $this->postJson("/api/v1/fleet/trips/{$tid}/start", ['reading' => 10020, 'started_at' => '2026-11-20 08:10'])->assertOk();
        $this->travelTo('2026-11-20 16:45:00');
        $this->postJson("/api/v1/fleet/trips/{$tid}/finish", ['reading' => 10160, 'ended_at' => '2026-11-20 16:40', 'notes' => 'All fine'])->assertOk();
        $this->getJson("/api/v1/fleet/trips/{$tid}")->assertJsonPath('data.distance', 140)->assertJsonPath('data.status', 'completed');
        $this->actingAs($this->officer)->getJson("/api/v1/fleet/units/{$this->unit}")->assertJsonPath('data.reading', 10160)->assertJsonPath('data.readings.0.source', 'logbook');
        $this->postJson("/api/v1/fleet/trips/{$tid}/confirm")->assertOk();
        $this->getJson("/api/v1/fleet/requests/{$id}")->assertJsonPath('data.status', 'completed');

        $this->actingAs($this->staff)->getJson('/api/v1/fleet/requests')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/fleet/trips')->assertForbidden();
    }

    public function test_transport_company_trips_raise_one_payment_request(): void
    {
        $item = app(CompanyContext::class)->run($this->company, function () {
            $year = app(FiscalCalendar::class)->createYear('2026-01-01');
            $acc = Account::create(['code' => '5300', 'name' => 'Hired transport', 'type' => 'expense', 'is_postable' => true, 'is_budgetable' => true, 'is_active' => true]);
            AccountingSetting::current()->fill(['budget_release' => 'none', 'budget_control' => 'warn'])->save();
            $budget = Budget::create(['fiscal_year_id' => $year->id, 'title' => 'Budget 2026', 'phasing' => 'quarter', 'status' => 'approved', 'created_by' => $this->officer->id]);
            $item = BudgetItem::create(['budget_id' => $budget->id, 'department_id' => $this->dept, 'account_id' => $acc->id, 'description' => 'Hired transport', 'quantity' => 1, 'unit_cost' => 2000000,
                'original_amount' => 2000000, 'revised_amount' => 2000000]);
            app(BudgetService::class)->record($item->id, 'approved', 2000000, $budget, 'Approved');
            WorkflowTemplate::where('process', 'transport-request')->delete();

            return $item->id;
        });
        $supplier = app(CompanyContext::class)->run($this->company, fn () => Supplier::create(['code' => 'S1', 'name' => 'Safari Transport Ltd', 'kind' => 'company', 'is_active' => true])->id);
        $this->actingAs($this->officer)->postJson('/api/v1/fleet/contracts', ['supplier_id' => $supplier, 'reference' => 'CT-9', 'mode' => 'km', 'rate' => 1500, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-10'])->assertCreated();
        $contract = $this->postJson('/api/v1/fleet/contracts', ['supplier_id' => $supplier, 'reference' => 'CT-10', 'mode' => 'km', 'rate' => 1500, 'starts_on' => '2026-11-01', 'ends_on' => '2027-10-31'])->json('data.id');
        $old = \Modules\Fleet\Models\AgencyContract::withoutGlobalScopes()->where('reference', 'CT-9')->value('id');

        $trip = ['purpose' => 'Supplies to clinic', 'destination' => 'Kisarawe', 'departs_at' => '2026-11-19 08:00', 'returns_at' => '2026-11-19 18:00'];
        $this->postJson('/api/v1/fleet/trips', $trip + ['fleet_agency_contract_id' => $old])->assertUnprocessable()->assertJsonValidationErrors(['trip' => 'not valid']);
        $tid = $this->postJson('/api/v1/fleet/trips', $trip + ['fleet_agency_contract_id' => $contract])->assertCreated()->json('data.id');
        $this->travelTo('2026-11-19 18:05:00');
        $this->postJson("/api/v1/fleet/trips/{$tid}/start", ['started_at' => '2026-11-19 08:00'])->assertOk();
        $this->postJson("/api/v1/fleet/trips/{$tid}/finish", ['ended_at' => '2026-11-19 18:00'])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
        $this->postJson("/api/v1/fleet/trips/{$tid}/finish", ['ended_at' => '2026-11-19 18:00', 'quantity' => 120])->assertOk();
        $this->postJson("/api/v1/fleet/trips/{$tid}/confirm")->assertOk();

        $this->getJson("/api/v1/fleet/contracts/{$contract}/unbatched?from_date=2026-11-01&to_date=2026-11-30")->assertJsonPath('total', 180000);
        $b = $this->postJson("/api/v1/fleet/contracts/{$contract}/batch", ['from_date' => '2026-11-01', 'to_date' => '2026-11-30', 'department_id' => $this->dept, 'budget_item_id' => $item])
            ->assertCreated()->assertJsonPath('data.number', 'TB-2026-00001')->json('data');
        $this->getJson("/api/v1/payments/requests/{$b['payment_request_id']}")->assertJsonPath('data.total', 180000)->assertJsonPath('data.payees.0.name', 'Safari Transport Ltd')
            ->assertJsonPath('data.status', 'draft');
        $this->getJson('/api/v1/fleet/contracts')->assertJsonPath('batches.0.trips', 1)->assertJsonPath('data.0.unpaid', 0);
        $this->postJson("/api/v1/fleet/contracts/{$contract}/batch", ['from_date' => '2026-11-01', 'to_date' => '2026-11-30', 'department_id' => $this->dept, 'budget_item_id' => $item])->assertUnprocessable();
    }
}
