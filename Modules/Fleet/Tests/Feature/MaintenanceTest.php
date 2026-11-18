<?php

namespace Modules\Fleet\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Services\BudgetService;
use Modules\Fleet\Actions\ManageMaintenance;
use Modules\Fleet\Models\Assignment;
use Modules\Fleet\Models\Driver;
use Modules\Fleet\Models\FleetUnit;
use Modules\Fleet\Models\Maintenance;
use Modules\Fleet\Models\VehicleType;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $officer;

    private User $approver;

    private User $driverUser;

    private int $unit;

    private int $type;

    private int $dept;

    private int $item;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-11-18 09:00:00');
        [$this->company] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'fleet'], ['slug' => 'accounting'], ['slug' => 'budgeting'], ['slug' => 'procurement'], ['slug' => 'payments']]]);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->run($this->company, function () {
            Role::findOrCreate('transport-officer', 'web')->syncPermissions(['fleet.view', 'fleet.manage', 'procurement.request']);
            Role::findOrCreate('fleet-approver', 'web')->syncPermissions(['fleet.view']);
            $this->officer = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('transport-officer');
            $this->approver = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('fleet-approver');
            $this->driverUser = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
            $this->dept = Department::create(['name' => 'Transport', 'is_active' => true])->id;
            $emp = Employee::create(['employee_no' => 'D1', 'fname' => 'Juma', 'lname' => 'X', 'gender' => 'Male', 'department_id' => $this->dept, 'user_id' => $this->driverUser->id,
                'employment_status' => 'confirmed', 'date_of_birth' => '1985-01-01'])->id;
            $this->type = VehicleType::create(['name' => 'Pickup', 'kind' => 'vehicle'])->id;
            $u = FleetUnit::create(['kind' => 'vehicle', 'vehicle_type_id' => $this->type, 'registration' => 'T 100 AAA', 'name' => 'Hilux', 'ownership' => 'owned', 'status' => 'operational',
                'reading' => 14600, 'reading_date' => '2026-11-10', 'department_id' => $this->dept]);
            $u->readings()->create(['reading' => 10000, 'date' => '2026-06-01', 'source' => 'registration', 'status' => 'approved']);
            $this->unit = $u->id;
            Assignment::create(['fleet_unit_id' => $this->unit, 'fleet_driver_id' => Driver::create(['employee_id' => $emp, 'status' => 'active'])->id, 'starts_on' => '2026-11-01']);
            $year = app(FiscalCalendar::class)->createYear('2026-01-01');
            $acc = Account::create(['code' => '5500', 'name' => 'Vehicle repairs', 'type' => 'expense', 'is_postable' => true, 'is_budgetable' => true, 'is_active' => true]);
            AccountingSetting::current()->fill(['budget_release' => 'none', 'budget_control' => 'warn'])->save();
            $budget = Budget::create(['fiscal_year_id' => $year->id, 'title' => 'Budget 2026', 'phasing' => 'quarter', 'status' => 'approved', 'created_by' => $this->officer->id]);
            $this->item = BudgetItem::create(['budget_id' => $budget->id, 'department_id' => $this->dept, 'account_id' => $acc->id, 'description' => 'Repairs', 'quantity' => 1, 'unit_cost' => 5000000,
                'original_amount' => 5000000, 'revised_amount' => 5000000])->id;
            app(BudgetService::class)->record($this->item, 'approved', 5000000, $budget, 'Approved');
            $role = Role::where('name', 'fleet-approver')->value('id');
            foreach (['maintenance-request', 'maintenance-inspection', 'fleet-accident'] as $process) {
                $this->assertTrue(WorkflowTemplate::where('process', $process)->exists());
                WorkflowTemplate::where('process', $process)->delete();
                WorkflowTemplate::create(['process' => $process, 'company_branch_id' => null, 'is_active' => true])
                    ->steps()->create(['step_order' => 1, 'name' => 'Approver', 'approver_type' => 'role', 'role_id' => $role, 'allow_send_back' => true]);
            }
            WorkflowTemplate::where('process', 'purchase-requisition')->delete();
        });
        $this->giveActiveContracts($this->company);
    }

    private function approve(string $process): void
    {
        $wf = WorkflowInstance::where('process', $process)->where('status', 'pending')->latest('id')->value('id');
        $this->actingAs($this->approver)->postJson("/api/v1/workflow/requests/{$wf}/approve")->assertOk();
    }

    public function test_service_due_opens_a_job_that_is_inspected_bought_and_completed(): void
    {
        $this->actingAs($this->officer)->postJson('/api/v1/fleet/service-plans', ['name' => 'Minor service', 'vehicle_type_id' => $this->type, 'every_reading' => 5000, 'every_months' => 6,
            'warn_reading' => 500, 'tasks' => 'Engine oil and filter'])->assertCreated();
        $this->getJson("/api/v1/fleet/units/{$this->unit}")->assertJsonPath('data.services.0.state', 'due')->assertJsonPath('data.services.0.next_reading', 15000)
            ->assertJsonPath('data.services.0.left_reading', 400);

        $this->assertSame(1, app(CompanyContext::class)->run($this->company, fn () => app(ManageMaintenance::class)->autoServices()));
        $this->assertSame(0, app(CompanyContext::class)->run($this->company, fn () => app(ManageMaintenance::class)->autoServices()));
        $this->assertSame(1, $this->driverUser->notifications()->where('data->title', 'Service due')->count());
        $job = app(CompanyContext::class)->run($this->company, fn () => Maintenance::value('id'));
        $this->getJson('/api/v1/fleet/maintenance/due')->assertJsonPath('data.0.job.id', $job);

        $this->postJson("/api/v1/fleet/maintenance/{$job}/inspect", ['findings' => 'Oil due', 'workshop' => 'Own workshop', 'lines' => [
            ['kind' => 'parts', 'description' => 'Engine oil 7 L', 'quantity' => 1, 'unit_cost' => 80000], ['kind' => 'labour', 'description' => 'Labour', 'quantity' => 1, 'unit_cost' => 40000]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['budget_item_id']);
        $this->postJson("/api/v1/fleet/maintenance/{$job}/inspect", ['findings' => 'Oil due', 'workshop' => 'Own workshop', 'department_id' => $this->dept, 'budget_item_id' => $this->item, 'lines' => [
            ['kind' => 'parts', 'description' => 'Engine oil 7 L', 'quantity' => 1, 'unit_cost' => 80000], ['kind' => 'labour', 'description' => 'Labour', 'quantity' => 1, 'unit_cost' => 40000]]])->assertOk();
        $this->getJson("/api/v1/fleet/maintenance/{$job}")->assertJsonPath('data.status', 'inspection')->assertJsonPath('data.estimate', 120000);
        $this->approve('maintenance-inspection');
        $show = $this->actingAs($this->officer)->getJson("/api/v1/fleet/maintenance/{$job}")->assertJsonPath('data.status', 'sourcing')->json('data');
        $this->assertNotNull($show['requisition']);
        $req = app(CompanyContext::class)->run($this->company, fn () => PurchaseRequisition::with('lines')->find($show['requisition']['id']));
        $this->assertSame(120000.0, (float) $req->total);
        $this->assertSame('approved', $req->status);

        $this->postJson("/api/v1/fleet/maintenance/{$job}/start", ['date' => '2026-11-18'])->assertOk();
        $this->getJson("/api/v1/fleet/units/{$this->unit}")->assertJsonPath('data.status', 'non_operational');
        $this->postJson("/api/v1/fleet/maintenance/{$job}/complete", ['completed_on' => '2026-11-18', 'reading' => 14650, 'actual_cost' => 118000, 'invoice_reference' => 'INV-7'])->assertOk();
        $unit = $this->getJson("/api/v1/fleet/units/{$this->unit}")->json('data');
        $this->assertSame('operational', $unit['status']);
        $this->assertSame(118000.0, (float) $unit['cost_total']);
        $this->assertSame('ok', $unit['services'][0]['state']);
        $this->assertSame(19650.0, (float) $unit['services'][0]['next_reading']);
    }

    public function test_driver_reports_a_fault_and_an_accident(): void
    {
        $id = $this->actingAs($this->driverUser)->postJson('/api/v1/fleet/maintenance', ['fleet_unit_id' => $this->unit, 'kind' => 'corrective', 'description' => 'Brakes squeak'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->approve('maintenance-request');
        $this->assertSame(1, $this->officer->notifications()->where('data->title', 'Repair to inspect')->count());
        $this->actingAs($this->officer)->postJson("/api/v1/fleet/maintenance/{$id}/inspect", ['findings' => 'Pads adjusted, no parts', 'workshop' => 'Own workshop', 'lines' => []])->assertOk();
        $this->approve('maintenance-inspection');
        $this->actingAs($this->officer)->getJson("/api/v1/fleet/maintenance/{$id}")->assertJsonPath('data.status', 'sourcing')->assertJsonPath('data.requisition', null);

        $acc = $this->actingAs($this->driverUser)->post('/api/v1/fleet/accidents', ['fleet_unit_id' => $this->unit, 'happened_at' => '2026-11-18 07:30', 'location' => 'Morogoro road', 'description' => 'Rear-ended at lights',
            'injuries' => 0, 'police_report' => 'RB/123', 'photos' => [UploadedFile::fake()->image('rear.jpg')]], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->assertSame(1, $this->officer->notifications()->where('data->title', 'Accident reported')->count());
        $this->approve('fleet-accident');
        $this->actingAs($this->officer)->get("/api/v1/fleet/accidents/{$acc}/photos/0")->assertOk();
        $this->postJson("/api/v1/fleet/accidents/{$acc}/assess", ['outcome' => 'repair', 'assessment' => 'Bumper and tail light', 'insurance_claim' => 'CLM-1'])->assertOk();
        $this->getJson("/api/v1/fleet/accidents/{$acc}")->assertJsonPath('data.maintenance.status_label', 'To inspect')->assertJsonPath('data.can.assess', false);
        $this->getJson("/api/v1/fleet/units/{$this->unit}")->assertJsonPath('data.status', 'non_operational');
        $this->postJson("/api/v1/fleet/accidents/{$acc}/assess", ['outcome' => 'repair', 'assessment' => 'again'])->assertUnprocessable();
    }
}
