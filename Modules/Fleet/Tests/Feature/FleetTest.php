<?php

namespace Modules\Fleet\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Fleet\Actions\FleetAlerts;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class FleetTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $officer;

    private User $hrAdmin;

    private User $chief;

    private User $driverUser;

    private int $employee;

    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-11-18 09:00:00');
        [$this->company, $this->hrAdmin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'fleet']]]);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->run($this->company, function () {
            Role::findOrCreate('transport-officer', 'web')->syncPermissions(['fleet.view', 'fleet.manage']);
            Role::findOrCreate('chief', 'web')->syncPermissions(['fleet.view']);
            $this->officer = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('transport-officer');
            $this->chief = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('chief');
            $this->driverUser = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
            $dept = Department::create(['name' => 'Transport', 'is_active' => true]);
            $this->employee = Employee::create(['employee_no' => 'TR1', 'fname' => 'Juma', 'lname' => 'Driver', 'gender' => 'Male', 'department_id' => $dept->id,
                'user_id' => $this->driverUser->id, 'employment_status' => 'confirmed', 'date_of_birth' => '1985-01-01'])->id;
            $this->other = Employee::create(['employee_no' => 'TR2', 'fname' => 'Neema', 'lname' => 'Second', 'gender' => 'Female', 'department_id' => $dept->id,
                'employment_status' => 'confirmed', 'date_of_birth' => '1990-01-01'])->id;
            $this->assertTrue(WorkflowTemplate::where('process', 'fleet-reading-correction')->exists());
            WorkflowTemplate::where('process', 'fleet-reading-correction')->first()->steps()->update(['role_id' => Role::where('name', 'chief')->value('id')]);
        });
    }

    public function test_register_drivers_readings_and_compliance(): void
    {
        $this->actingAs($this->chief)->postJson('/api/v1/fleet/types', ['name' => 'Ambulance', 'kind' => 'vehicle'])->assertForbidden();
        $type = $this->actingAs($this->officer)->postJson('/api/v1/fleet/types', ['name' => 'Ambulance', 'kind' => 'vehicle', 'licence_classes' => ['c', 'D'], 'tank_capacity' => 80])
            ->assertCreated()->json('data.id');

        $this->postJson('/api/v1/fleet/units', ['kind' => 'vehicle', 'name' => 'Toyota Land Cruiser', 'ownership' => 'owned'])->assertUnprocessable()->assertJsonValidationErrors(['registration']);
        $this->postJson('/api/v1/fleet/units', ['kind' => 'vehicle', 'name' => 'Borrowed van', 'registration' => 'T 999 AAA', 'ownership' => 'borrowed'])->assertUnprocessable()->assertJsonValidationErrors(['owner_name']);
        $unit = $this->postJson('/api/v1/fleet/units', ['kind' => 'vehicle', 'vehicle_type_id' => $type, 'registration' => 't 123  abc', 'name' => 'Toyota Land Cruiser', 'ownership' => 'owned',
            'reading' => 45000, 'reading_date' => '2026-11-01'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/fleet/units', ['kind' => 'vehicle', 'registration' => 'T 123 ABC', 'name' => 'Duplicate', 'ownership' => 'owned'])->assertUnprocessable()->assertJsonValidationErrors(['registration']);
        $gen = $this->postJson('/api/v1/fleet/units', ['kind' => 'generator', 'name' => 'Main theatre generator', 'ownership' => 'owned', 'reading' => 1200])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/fleet/units/{$gen}")->assertJsonPath('data.unit', 'hours');

        $this->postJson("/api/v1/fleet/units/{$unit}/readings", ['reading' => 44000, 'date' => '2026-11-10'])->assertUnprocessable()->assertJsonValidationErrors(['reading']);
        $this->postJson("/api/v1/fleet/units/{$unit}/readings", ['reading' => 45500, 'date' => '2026-10-10'])->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $this->postJson("/api/v1/fleet/units/{$unit}/readings", ['reading' => 45500, 'date' => '2026-11-10'])->assertOk();
        $this->postJson("/api/v1/fleet/units/{$unit}/correction", ['reading' => 45050, 'reason' => 'Typed 45500 instead of 45050'])->assertOk();
        $this->getJson("/api/v1/fleet/units/{$unit}")->assertJsonPath('data.reading', 45500)->assertJsonPath('data.readings.0.status', 'pending');
        $wf = WorkflowInstance::where('process', 'fleet-reading-correction')->where('status', 'pending')->value('id');
        $this->actingAs($this->chief)->postJson("/api/v1/workflow/requests/{$wf}/approve")->assertOk();
        $this->actingAs($this->officer)->getJson("/api/v1/fleet/units/{$unit}")->assertJsonPath('data.reading', 45050);

        $this->getJson('/api/v1/fleet/drivers/employees?search=Juma')->assertJsonPath('data.0.id', $this->employee);
        $driver = $this->postJson('/api/v1/fleet/drivers', ['employee_id' => $this->employee])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/fleet/drivers', ['employee_id' => $this->employee])->assertUnprocessable();
        $this->postJson("/api/v1/fleet/units/{$unit}/assign", ['fleet_driver_id' => $driver, 'starts_on' => '2026-11-18'])->assertUnprocessable()->assertJsonValidationErrors(['fleet_driver_id' => 'no valid driving licence']);
        $this->post("/api/v1/fleet/drivers/{$driver}/licences", ['number' => 'DL-1', 'classes' => ['B'], 'issued_on' => '2021-11-30', 'expires_on' => '2026-11-30'], ['Accept' => 'application/json'])->assertOk();
        $this->postJson("/api/v1/fleet/units/{$unit}/assign", ['fleet_driver_id' => $driver, 'starts_on' => '2026-11-18'])->assertUnprocessable()->assertJsonValidationErrors(['fleet_driver_id' => 'does not cover']);
        $this->post("/api/v1/fleet/drivers/{$driver}/licences", ['number' => 'DL-2', 'classes' => ['b', 'c'], 'issued_on' => '2021-12-02', 'expires_on' => '2026-12-02',
            'document' => UploadedFile::fake()->create('licence.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertOk();
        $this->getJson("/api/v1/fleet/drivers/{$driver}")->assertJsonCount(2, 'data.licences')->assertJsonPath('data.licences.0.is_current', true)->assertJsonPath('data.licences.1.is_current', false);
        $hr = $this->actingAs($this->hrAdmin)->getJson("/api/v1/hr/employees/{$this->employee}/licences")->assertOk()->json('data');
        $this->assertSame(['DL-2', 'DL-1'], array_column($hr, 'licence_no'));
        $this->assertSame(['B', 'C'], $hr[0]['classes']);
        $this->assertTrue($hr[0]['licence']['is_driving']);
        $this->actingAs($this->officer);
        $this->getJson("/api/v1/fleet/drivers?unit_id={$unit}")->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/fleet/units/{$unit}/assign", ['fleet_driver_id' => $driver, 'starts_on' => '2026-11-18', 'ends_on' => '2026-11-25'])->assertOk();
        $this->getJson("/api/v1/fleet/units/{$unit}")->assertJsonPath('data.driver.name', 'Juma Driver');
        $this->actingAs($this->driverUser)->getJson('/api/v1/self/fleet')->assertJsonPath('data.vehicles.0.label', 'T 123 ABC Toyota Land Cruiser')->assertJsonPath('data.licence.number', 'DL-2');

        $this->actingAs($this->officer)->post("/api/v1/fleet/units/{$unit}/compliance", ['type' => 'insurance', 'reference' => 'POL-1', 'provider' => 'Jubilee', 'cover' => 'Comprehensive',
            'starts_on' => '2025-12-02', 'expires_on' => '2026-12-02', 'amount' => 1200000], ['Accept' => 'application/json'])->assertOk();
        $this->post("/api/v1/fleet/units/{$unit}/compliance", ['type' => 'road_licence', 'expires_on' => '2026-11-11', 'amount' => 250000], ['Accept' => 'application/json'])->assertOk();
        $this->postJson("/api/v1/fleet/units/{$unit}/costs", ['date' => '2026-11-15', 'category' => 'tyres', 'amount' => 800000])->assertOk();
        $show = $this->getJson("/api/v1/fleet/units/{$unit}")->json('data');
        $this->assertSame(2250000.0, (float) $show['cost_total']);
        $this->assertSame(45000.0, (float) $show['cost_per_unit']);
        $this->assertSame(-7, $show['compliance'][1]['days_left']);

        $cal = collect($this->getJson('/api/v1/fleet/compliance?days=30')->json('data'));
        $this->assertSame(['Road licence', 'Insurance', 'Driving licence'], $cal->pluck('what')->all());
        $this->getJson('/api/v1/fleet/dashboard')->assertJsonPath('data.overdue', 1)->assertJsonPath('data.due_soon', 2)->assertJsonPath('data.units', 2);

        $result = app(CompanyContext::class)->run($this->company, fn () => app(FleetAlerts::class)->run());
        $this->assertSame(0, $result['ended']);
        $this->assertGreaterThan(0, $result['reminders']);
        $this->assertSame(1, $this->driverUser->notifications()->where('data->title', 'Road licence expired 7 day(s) ago')->count());

        $this->travelTo('2026-11-26 07:00:00');
        $this->assertSame(1, app(CompanyContext::class)->run($this->company, fn () => app(FleetAlerts::class)->run())['ended']);
        $this->getJson("/api/v1/fleet/units/{$unit}")->assertJsonPath('data.driver', null);

        $this->postJson("/api/v1/fleet/units/{$unit}/status", ['status' => 'non_operational'])->assertUnprocessable()->assertJsonValidationErrors(['note']);
        $this->postJson("/api/v1/fleet/units/{$unit}/status", ['status' => 'non_operational', 'note' => 'Gearbox'])->assertOk();
        $this->getJson('/api/v1/fleet/units?status=non_operational')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.registration', 'T 123 ABC');
        $this->getJson("/api/v1/fleet/units/{$unit}")->assertJsonCount(2, 'data.history');
    }

    public function test_other_companies_cannot_reach_fleet_records(): void
    {
        $unit = $this->actingAs($this->officer)->postJson('/api/v1/fleet/units', ['kind' => 'vehicle', 'registration' => 'T 1 A', 'name' => 'Pickup', 'ownership' => 'owned'])->json('data.id');
        $this->postJson("/api/v1/fleet/units/{$unit}/costs", ['date' => '2026-11-15', 'category' => 'other', 'amount' => 1000])->assertOk();
        $cost = \Modules\Fleet\Models\Cost::query()->value('id');
        [$beta, $betaAdmin] = $this->createCompany('Beta', ['modules' => [['slug' => 'fleet']]]);
        $this->actingAs($betaAdmin)->getJson("/api/v1/fleet/units/{$unit}")->assertNotFound();
        $this->deleteJson("/api/v1/fleet/costs/{$cost}")->assertNotFound();
    }
}
