<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\CompanyBranch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class JobPositionTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private int $hq;

    private int $arusha;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->admin] = $this->createCompany('Alpha', [
            'modules' => [['slug' => 'hr']],
            'branches' => [['name' => 'Head Office', 'is_headquarters' => true], ['name' => 'Arusha']],
        ]);
        setPermissionsTeamId($this->company->id);
        $this->hq = CompanyBranch::withoutCompanyScope()->where('company_id', $this->company->id)->where('is_headquarters', true)->value('id');
        $this->arusha = CompanyBranch::withoutCompanyScope()->where('company_id', $this->company->id)->where('name', 'Arusha')->value('id');
        $this->actingAs($this->admin);
    }

    private function department(string $name, ?int $branch, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/hr/structure/departments', ['name' => $name, 'company_branch_id' => $branch] + $extra);
    }

    public function test_same_department_name_is_allowed_per_branch_but_not_next_to_a_company_wide_one(): void
    {
        $this->department('Nursing', $this->hq)->assertCreated();
        $this->department('Nursing', $this->arusha)->assertCreated()->assertJsonPath('data.branch.name', 'Arusha');
        $this->department('nursing', $this->arusha)->assertUnprocessable()->assertJsonValidationErrors(['name' => 'already exists in Arusha']);
        $this->department('Nursing', null)->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->department('Pharmacy', null)->assertCreated();
        $this->department('Pharmacy', $this->arusha)->assertUnprocessable()->assertJsonValidationErrors(['name' => 'for all branches']);
    }

    public function test_positions_are_unique_per_department_and_follow_their_department(): void
    {
        $hqNursing = $this->department('Nursing', $this->hq)->json('data.id');
        $aruNursing = $this->department('Nursing', $this->arusha)->json('data.id');

        $hqNurse = $this->postJson('/api/v1/hr/job-positions', ['title' => 'Registered Nurse', 'department_id' => $hqNursing])->assertCreated()
            ->assertJsonPath('data.department.branch', 'Head Office')->json('data.id');
        $this->postJson('/api/v1/hr/job-positions', ['title' => 'registered nurse', 'department_id' => $hqNursing])->assertUnprocessable()->assertJsonValidationErrors('title');
        $aruNurse = $this->postJson('/api/v1/hr/job-positions', ['title' => 'Registered Nurse', 'department_id' => $aruNursing])->assertCreated()->json('data.id');

        $this->getJson('/api/v1/hr/job-positions?filter[branch_id]='.$this->arusha)->assertOk()->assertJsonFragment(['id' => $aruNurse])->assertJsonMissing(['id' => $hqNurse]);
        $this->getJson('/api/v1/hr/positions?department_id='.$aruNursing)->assertOk()->assertJsonFragment(['title' => 'Registered Nurse', 'branch' => 'Arusha']);

        $base = ['employee_no' => 'E1', 'fname' => 'Asha', 'lname' => 'Ali', 'gender' => 'Female', 'emp_type' => 'full_time',
            'employment_date' => '2026-11-01', 'employment_status' => 'confirmed'];
        $this->postJson('/api/v1/hr/employees', $base + ['company_branch_id' => $this->hq, 'department_id' => $aruNursing, 'job_position_id' => $aruNurse])
            ->assertUnprocessable()->assertJsonValidationErrors(['department_id' => 'this branch']);
        $this->postJson('/api/v1/hr/employees', $base + ['company_branch_id' => $this->hq, 'department_id' => $hqNursing, 'job_position_id' => $aruNurse])
            ->assertUnprocessable()->assertJsonValidationErrors(['job_position_id' => 'chosen department']);
        $this->postJson('/api/v1/hr/employees', $base + ['company_branch_id' => $this->arusha, 'department_id' => $aruNursing, 'job_position_id' => $aruNurse])->assertCreated();

        $this->deleteJson("/api/v1/hr/job-positions/{$aruNurse}")->assertStatus(422);
        $this->putJson("/api/v1/hr/job-positions/{$aruNurse}", ['title' => 'Registered Nurse', 'department_id' => $aruNursing, 'is_active' => false])->assertOk()->assertJsonPath('data.staff_count', 1);
        $this->getJson('/api/v1/hr/positions?department_id='.$aruNursing)->assertJsonMissing(['id' => $aruNurse]);
        $this->deleteJson("/api/v1/hr/job-positions/{$hqNurse}")->assertNoContent();

        $this->getJson("/api/v1/hr/structure/departments/{$aruNursing}")->assertJsonPath('data.positions.0.title', 'Registered Nurse');
    }

    public function test_managing_positions_needs_permission(): void
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $user->assignRole('employee');
        $this->actingAs($user)->postJson('/api/v1/hr/job-positions', ['title' => 'X', 'department_id' => 1])->assertForbidden();
    }
}
