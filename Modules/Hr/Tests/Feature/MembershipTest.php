<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\SaccosMember;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class MembershipTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_members_of_schemes_saccos_and_reliefs(): void
    {
        [$company, $admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'payroll']]]);
        setPermissionsTeamId($company->id);
        [$asha, $staff] = app(CompanyContext::class)->run($company, function () use ($company) {
            $ward = Department::create(['name' => 'Ward']);
            $asha = Employee::create(['employee_no' => 'W1', 'fname' => 'Asha', 'lname' => 'X', 'gender' => 'Female', 'department_id' => $ward->id, 'emp_type' => 'full_time', 'employment_status' => 'confirmed']);
            $staff = tap(User::factory()->create(['company_id' => $company->id]))->assignRole('employee');

            return [$asha, $staff];
        });
        $this->actingAs($admin);

        $this->putJson("/api/v1/hr/employees/{$asha->id}/deductions/nssf", ['contribution_no' => 'N100', 'value' => 10])->assertOk();
        $this->getJson('/api/v1/payroll/memberships?scheme=nssf')->assertOk()->assertJsonPath('meta.label', 'NSSF')->assertJsonPath('meta.active', 1)
            ->assertJsonPath('data.0.contribution_no', 'N100')->assertJsonPath('data.0.value', 10)->assertJsonPath('data.0.employer_value', 10);

        $this->putJson("/api/v1/payroll/memberships/saccos/{$asha->id}", ['amount' => 50000, 'from_date' => '2026-01-01'])->assertOk();
        $this->getJson('/api/v1/payroll/memberships?scheme=saccos')->assertJsonPath('data.0.value', 50000)->assertJsonPath('data.0.from_date', '2026-01-01');
        $this->putJson("/api/v1/payroll/memberships/relief/{$asha->id}", ['amount' => 20000, 'description' => 'Housing'])->assertOk();
        $this->getJson('/api/v1/payroll/memberships?scheme=relief')->assertJsonPath('data.0.description', 'Housing');
        $this->putJson("/api/v1/payroll/memberships/relief/{$asha->id}", ['amount' => 20000, 'from_date' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors('from_date');

        app(CompanyContext::class)->run($company, fn () => SaccosMember::where('employee_id', $asha->id)->update(['total_contribution' => 100000]));
        $this->deleteJson("/api/v1/payroll/memberships/saccos/{$asha->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/payroll/memberships/relief/{$asha->id}")->assertNoContent();
        $this->getJson('/api/v1/payroll/memberships?scheme=relief')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/payroll/memberships?scheme=unknown')->assertUnprocessable();

        $this->actingAs($staff)->getJson('/api/v1/payroll/memberships?scheme=nssf')->assertForbidden();
    }
}
