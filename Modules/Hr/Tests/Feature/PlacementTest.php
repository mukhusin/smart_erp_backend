<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeePlacement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class PlacementTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-11 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr']]]);
        setPermissionsTeamId($this->company->id);
        $this->actingAs($this->admin);
    }

    private function employee(string $no, string $type): Employee
    {
        $admin = Employee::withoutCompanyScope()->where('company_id', $this->company->id)->first();

        return Employee::withoutCompanyScope()->create([
            'company_id' => $this->company->id, 'employee_no' => $no, 'fname' => $no, 'lname' => 'Test', 'gender' => 'Female',
            'department_id' => $admin->department_id, 'job_position_id' => $admin->job_position_id, 'emp_type' => $type, 'employment_status' => 'confirmed',
        ]);
    }

    public function test_internship_periods_follow_hrms_rules(): void
    {
        $intern = $this->employee('INT1', 'internship');
        $base = "/api/v1/hr/employees/{$intern->id}/placements";

        $this->postJson($base, ['type' => 'residency', 'start_date' => '2026-07-01', 'period' => 12])
            ->assertUnprocessable()->assertJsonValidationErrors(['type' => 'not on residency']);

        $first = $this->postJson($base, ['type' => 'internship', 'start_date' => '2026-07-01', 'period' => 6])
            ->assertCreated()->assertJsonPath('data.end_date', '2026-12-31')->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.year', 2026)->assertJsonPath('data.days_left', 50)->json('data.id');

        $this->postJson($base, ['type' => 'internship', 'start_date' => '2026-12-01', 'period' => 6])
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date' => 'overlaps']);
        $next = $this->postJson($base, ['type' => 'internship', 'start_date' => '2027-01-01', 'period' => 6])
            ->assertCreated()->assertJsonPath('data.status', 'upcoming')->json('data.id');

        $this->getJson("/api/v1/hr/employees/{$intern->id}")->assertJsonPath('data.placement.id', $first)->assertJsonPath('data.counts.placements', 2);
        $this->getJson('/api/v1/hr/summary')->assertJsonPath('data.placements_active', 1)->assertJsonPath('data.without_contract', 1);

        $this->travelTo('2027-01-01 01:00:00');
        $this->artisan('contract:status')->assertSuccessful();
        $this->assertSame('ended', EmployeePlacement::find($first)->status);
        $this->assertSame('active', EmployeePlacement::find($next)->status);

        $this->postJson("{$base}/{$next}/stop", ['date' => '2027-02-15', 'comment' => 'Finished early'])
            ->assertOk()->assertJsonPath('data.status', 'ended')->assertJsonPath('data.end_date', '2027-02-15');
    }

    public function test_interns_list_with_filters(): void
    {
        $intern = $this->employee('INT1', 'internship');
        $resident = $this->employee('RES1', 'resident');
        $this->postJson("/api/v1/hr/employees/{$intern->id}/placements", ['type' => 'internship', 'start_date' => '2026-06-01', 'period' => 6])->assertCreated();
        $this->postJson("/api/v1/hr/employees/{$resident->id}/placements", ['type' => 'residency', 'start_date' => '2026-01-01', 'period' => 36])->assertCreated();

        $this->getJson('/api/v1/hr/placements')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.employee.employee_no', 'INT1');
        $this->getJson('/api/v1/hr/placements?filter[type]=residency')->assertJsonCount(1, 'data')->assertJsonPath('data.0.type_label', 'Residency');
        $this->getJson('/api/v1/hr/placements?filter[ending_within]=30')->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.employee_no', 'INT1');
        $this->getJson('/api/v1/hr/placements?filter[search]=RES')->assertJsonCount(1, 'data');

        $staff = User::factory()->create(['company_id' => $this->company->id]);
        $staff->assignRole('employee');
        $this->actingAs($staff)->getJson('/api/v1/hr/placements')->assertForbidden();
    }
}
