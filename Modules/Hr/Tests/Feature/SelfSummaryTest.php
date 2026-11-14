<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Department;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class SelfSummaryTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_employee_sees_own_dashboard_with_sections_of_enabled_modules(): void
    {
        $this->travelTo('2026-11-14 09:00:00');
        [$company] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'self-service'], ['slug' => 'leave'], ['slug' => 'payroll']]]);
        setPermissionsTeamId($company->id);
        [$me] = app(CompanyContext::class)->run($company, function () use ($company) {
            $me = User::factory()->create(['company_id' => $company->id]);
            $me->assignRole('employee');
            $employee = Employee::create(['employee_no' => 'ST9', 'fname' => 'Asha', 'lname' => 'Ali', 'gender' => 'Female', 'emp_type' => 'full_time',
                'employment_status' => 'confirmed', 'user_id' => $me->id, 'department_id' => Department::value('id'), 'employment_date' => '2020-01-01']);
            EmpLeave::create(['employee_id' => $employee->id, 'leave_type_id' => LeaveType::value('id'), 'start_date' => '2026-12-01', 'end_date' => '2026-12-05',
                'requested_start_date' => '2026-12-01', 'requested_end_date' => '2026-12-05', 'days' => 5, 'days_requested' => 5, 'leave_year' => 2026, 'status' => EmpLeave::PENDING]);

            return [$me, $employee];
        });
        $this->giveActiveContracts($company);

        $data = $this->actingAs($me)->getJson('/api/v1/self/summary')->assertOk()->json('data');

        $this->assertSame('Asha Ali', $data['employee']['name']);
        $this->assertEqualsWithDelta(6.9, $data['employee']['years_of_service'], 0.1);
        $this->assertSame('2030-12-31', $data['contract']['end']);
        $this->assertFalse($data['contract']['renewal_open']);
        $this->assertCount(7, $data['days']);
        $this->assertSame('2026-11-14', $data['days'][0]['date']);
        $this->assertSame(1, $data['leave']['pending']);
        $this->assertSame(1, $data['pending_requests']);
        $this->assertNull($data['payroll']['last']);
        $this->assertNull($data['attendance']);
        $this->assertNull($data['overtime']);
        $this->assertNull($data['loans']);

        $other = User::factory()->create(['company_id' => $company->id]);
        app(CompanyContext::class)->run($company, fn () => $other->assignRole('employee'));
        $this->actingAs($other)->getJson('/api/v1/self/summary')->assertNotFound();
    }
}
