<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\CompanyBranch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobPosition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Mail\StaffInviteMail;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class EmployeeRegistrationTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;
    private User $admin;
    private int $branch;
    private int $department;
    private int $section;
    private int $position;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr']]]);
        setPermissionsTeamId($this->company->id);

        $ids = fn (string $model) => $model::withoutCompanyScope()->where('company_id', $this->company->id)->value('id');
        $this->branch = $ids(CompanyBranch::class);
        $this->department = $ids(Department::class);
        $this->position = $ids(JobPosition::class);
        $this->section = Department::withoutCompanyScope()->create([
            'company_id' => $this->company->id, 'parent_id' => $this->department, 'name' => 'Recruitment', 'is_active' => true,
        ])->id;
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'employee_no' => 'EMP-0002',
            'fname' => 'Asha',
            'lname' => 'Mussa',
            'gender' => 'Female',
            'email' => 'Asha@Alpha.test',
            'company_branch_id' => $this->branch,
            'department_id' => $this->department,
            'section_id' => $this->section,
            'job_position_id' => $this->position,
            'emp_type' => 'full_time',
            'employment_date' => '2026-11-01',
            'employment_status' => 'probation',
            'probation_start' => '2026-11-01',
            'probation_period' => 6,
        ];
    }

    public function test_registers_employee_with_account_and_invite(): void
    {
        Mail::fake();

        $res = $this->actingAs($this->admin)->postJson('/api/v1/hr/employees', $this->payload(['create_account' => true]))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Asha Mussa')
            ->assertJsonPath('data.section.name', 'Recruitment')
            ->assertJsonPath('data.account.username', 'asha@alpha.test')
            ->assertJsonPath('data.account.has_set_password', false)
            ->assertJsonPath('data.details.probation_end', '2027-04-30')
            ->assertJsonPath('meta.invite_sent', true);

        $user = User::find($res->json('data.account.id'));
        $this->assertSame($this->company->id, $user->company_id);
        $this->assertSame($this->branch, $user->company_branch_id);
        $this->assertSame(['employee'], $user->getRoleNames()->all());
        Mail::assertSent(StaffInviteMail::class, fn ($m) => $m->hasTo('asha@alpha.test') && str_contains($m->url, '/set-password?token='));
    }

    public function test_registers_employee_without_account(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)->postJson('/api/v1/hr/employees', $this->payload(['email' => null, 'employment_status' => 'confirmed']))
            ->assertCreated()->assertJsonPath('data.account', null)->assertJsonPath('meta.invite_sent', null)
            ->assertJsonPath('data.details.probation_start', null);

        Mail::assertNothingSent();
    }

    public function test_validates_company_data_and_account_rules(): void
    {
        [$beta] = $this->createCompany('Beta', ['modules' => [['slug' => 'hr']]]);
        setPermissionsTeamId($this->company->id);
        $betaDepartment = Department::withoutCompanyScope()->where('company_id', $beta->id)->value('id');
        $otherSection = Department::withoutCompanyScope()->create(['company_id' => $this->company->id, 'name' => 'Finance', 'is_active' => true])->id;

        $this->actingAs($this->admin)->postJson('/api/v1/hr/employees', $this->payload([
            'employee_no' => 'EMP-0001',
            'department_id' => $betaDepartment,
            'create_account' => true,
            'email' => null,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['employee_no', 'department_id', 'email']);

        $this->actingAs($this->admin)->postJson('/api/v1/hr/employees', $this->payload(['section_id' => $otherSection, 'probation_period' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors(['section_id', 'probation_period']);

        $this->actingAs($this->admin)->postJson('/api/v1/hr/employees', $this->payload(['create_account' => true, 'email' => $this->admin->email]))
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_lists_only_own_company_with_filters(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/hr/employees', $this->payload())->assertCreated();
        [, $betaAdmin] = $this->createCompany('Beta', ['modules' => [['slug' => 'hr']]]);
        $betaEmployee = Employee::withoutCompanyScope()->where('user_id', $betaAdmin->id)->first();
        setPermissionsTeamId($this->company->id);

        $this->actingAs($this->admin)->getJson('/api/v1/hr/employees')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($this->admin)->getJson('/api/v1/hr/employees?filter[has_account]=0')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_no', 'EMP-0002');
        $this->actingAs($this->admin)->getJson("/api/v1/hr/employees?filter[department_id]={$this->section}&filter[search]=asha")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson("/api/v1/hr/employees/{$betaEmployee->id}")->assertNotFound();
    }

    public function test_account_for_existing_employee_and_resend_invite(): void
    {
        Mail::fake();
        $id = $this->actingAs($this->admin)->postJson('/api/v1/hr/employees', $this->payload())->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/v1/hr/employees/{$id}/account", ['username' => 'asha'])
            ->assertCreated()->assertJsonPath('data.account.username', 'asha')->assertJsonPath('meta.invite_sent', true);
        $this->actingAs($this->admin)->postJson("/api/v1/hr/employees/{$id}/account")->assertUnprocessable();
        $this->actingAs($this->admin)->postJson("/api/v1/hr/employees/{$id}/invite")->assertOk();

        Mail::assertSent(StaffInviteMail::class, 2);
    }

    public function test_next_number_and_permissions(): void
    {
        $yy = now()->format('y');
        $this->actingAs($this->admin)->getJson('/api/v1/hr/employees/next-number')->assertOk()->assertJsonPath('data.employee_no', 'ST1');
        $this->getJson('/api/v1/hr/employees/next-number?emp_type=part_time')->assertJsonPath('data.employee_no', 'PT1');
        $this->getJson('/api/v1/hr/employees/next-number?emp_type=internship')->assertJsonPath('data.employee_no', "INT/A/OTHER:0001/{$yy}");
        $this->getJson('/api/v1/hr/employees/next-number?emp_type=resident')->assertJsonPath('data.employee_no', "RES/A/SN:001/{$yy}");
        $this->getJson('/api/v1/hr/number-formats')->assertJsonPath('meta.using_built_in', true)->assertJsonPath('meta.renumber_on_contract_change', true);

        $staff = User::factory()->create(['company_id' => $this->company->id, 'company_branch_id' => $this->branch]);
        $staff->assignRole('employee');
        $this->actingAs($staff)->getJson('/api/v1/hr/employees')->assertForbidden();
        $this->actingAs($staff)->postJson('/api/v1/hr/employees', $this->payload())->assertForbidden();
    }
}
