<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\CompanyBranch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\HeadOfDepartment;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class EmployeeScopeTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private int $hq;

    private int $branch;

    private Department $nursing;

    private Department $ward;

    private Department $lab;

    private Department $pharmacy;

    /** @var array<string, Employee> */
    private array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr']], 'branches' => [['name' => 'Head Office', 'is_headquarters' => true], ['name' => 'Arusha']]]);
        setPermissionsTeamId($this->company->id);

        app(CompanyContext::class)->run($this->company, function () {
            $this->hq = CompanyBranch::where('is_headquarters', true)->value('id');
            $this->branch = CompanyBranch::where('is_headquarters', false)->value('id');
            $this->nursing = Department::create(['name' => 'Nursing', 'is_active' => true]);
            $this->ward = Department::create(['name' => 'Ward 1', 'parent_id' => $this->nursing->id, 'is_active' => true]);
            $this->lab = Department::create(['name' => 'Laboratory', 'is_active' => true]);
            $this->pharmacy = Department::create(['name' => 'Pharmacy', 'is_active' => true]);

            $make = fn (string $name, Department $dept, ?Department $section = null, ?int $branch = null) => $this->staff[$name] = Employee::create([
                'employee_no' => strtoupper($name), 'fname' => $name, 'lname' => 'Test', 'gender' => 'Female', 'company_branch_id' => $branch ?? $this->hq,
                'department_id' => $dept->id, 'section_id' => $section?->id, 'employment_status' => 'confirmed',
            ]);
            $make('hod', $this->nursing);
            $make('nurse', $this->nursing, $this->ward);
            $make('nurse2', $this->nursing);
            $make('labtech', $this->lab, null, $this->branch);
            $make('pharmacist', $this->pharmacy);
            HeadOfDepartment::create(['employee_id' => $this->staff['hod']->id, 'department_id' => $this->lab->id]);
        });
    }

    private function userFor(string $employee, array $permissions): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'company_branch_id' => $this->staff[$employee]->company_branch_id]);
        $this->staff[$employee]->update(['user_id' => $user->id]);
        app(CompanyContext::class)->run($this->company, function () use ($user, $permissions, $employee) {
            $role = Role::findOrCreate("scope-{$employee}", 'web');
            $role->syncPermissions($permissions);
            $user->assignRole($role);
        });

        return $user;
    }

    private function listed(User $user): array
    {
        return collect($this->actingAs($user)->getJson('/api/v1/hr/employees?per_page=100')->assertOk()->json('data'))->pluck('employee_no')->sort()->values()->all();
    }

    public function test_head_of_department_sees_own_and_headed_departments(): void
    {
        $hod = $this->userFor('hod', ['hr.employees.view-department']);

        $this->assertSame(['HOD', 'LABTECH', 'NURSE', 'NURSE2'], $this->listed($hod));
        $this->getJson('/api/v1/hr/employees?per_page=5')->assertJsonPath('meta.scope.all', false)->assertJsonPath('meta.scope.label', 'Employees of Laboratory, Nursing');
        $this->getJson("/api/v1/hr/employees/{$this->staff['nurse']->id}")->assertOk();
        $this->getJson("/api/v1/hr/employees/{$this->staff['pharmacist']->id}")->assertNotFound();
        $this->getJson("/api/v1/hr/employees/{$this->staff['pharmacist']->id}/contracts")->assertNotFound();
        $this->getJson('/api/v1/hr/summary')->assertJsonPath('data.total', 4);
    }

    public function test_section_and_branch_scopes(): void
    {
        $nurse = $this->userFor('nurse', ['hr.employees.view-section']);
        $this->assertSame(['NURSE'], $this->listed($nurse));

        $labtech = $this->userFor('labtech', ['hr.employees.view-branch']);
        $this->assertSame(['LABTECH'], $this->listed($labtech));

        $both = $this->userFor('pharmacist', ['hr.employees.view-department', 'hr.employees.view-branch']);
        $this->assertSame(['EMP-0001', 'HOD', 'NURSE', 'NURSE2', 'PHARMACIST'], $this->listed($both));
    }

    public function test_all_employees_permission_and_no_access(): void
    {
        $hr = $this->userFor('nurse2', ['hr.employees.view']);
        $this->assertCount(6, $this->listed($hr));
        $this->getJson('/api/v1/hr/summary')->assertJsonPath('data.scope.all', true);

        $none = User::factory()->create(['company_id' => $this->company->id]);
        $none->assignRole('employee');
        $this->actingAs($none)->getJson('/api/v1/hr/employees')->assertForbidden();
    }

    public function test_scope_applies_to_writes_too(): void
    {
        $hod = $this->userFor('hod', ['hr.employees.view-department', 'hr.employees.edit']);
        $this->actingAs($hod)->postJson("/api/v1/hr/employees/{$this->staff['nurse']->id}/experiences", ['company_name' => 'Aga Khan', 'position' => 'Nurse', 'start_date' => '2020-01-01'])
            ->assertCreated();
        $this->postJson("/api/v1/hr/employees/{$this->staff['pharmacist']->id}/experiences", ['company_name' => 'Aga Khan', 'position' => 'Nurse', 'start_date' => '2020-01-01'])
            ->assertNotFound();
    }
}
