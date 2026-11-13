<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Department;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\LeaveType;
use App\Models\NatureOfContract;
use App\Models\OtherDeduction;
use App\Models\SalaryAdjustment;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Workflow\Models\WorkflowTemplate;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class PayItemsTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private User $user;

    private Employee $nurse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-26 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'payroll'], ['slug' => 'allowance'], ['slug' => 'leave'], ['slug' => 'self-service']]]);
        setPermissionsTeamId($this->company->id);
        $this->inCompany(function () {
            $ward = Department::create(['name' => 'Ward']);
            $stc = NatureOfContract::create(['contract_name' => 'Specific time', 'code' => 'STC', 'employment_type' => 'full_time', 'has_payroll' => true, 'has_allowance' => true, 'has_annual_leave' => true]);
            $this->user = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
            $this->nurse = Employee::create(['employee_no' => 'A', 'fname' => 'Asha', 'lname' => 'X', 'gender' => 'Female', 'department_id' => $ward->id, 'user_id' => $this->user->id,
                'emp_type' => 'full_time', 'emp_category' => 'Primary', 'employment_status' => 'confirmed', 'has_annual_leave' => true, 'employment_date' => '2025-01-01']);
            EmployeeContract::create(['employee_id' => $this->nurse->id, 'nature_of_contract_id' => $stc->id, 'contract_no' => 'A', 'basic_salary' => 1000000, 'period' => 12,
                'start_date' => '2026-01-01', 'end_date' => '2027-06-30', 'status' => 'active', 'is_active' => true]);
            WorkflowTemplate::whereIn('process', ['assigned-allowance', 'other-deduction', 'salary-adjustment'])->delete();
        });
    }

    private function inCompany(callable $fn): mixed
    {
        return app(CompanyContext::class)->run($this->company, $fn);
    }

    private function category(string $name, string $code, string $unit = 'month')
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/allowance/categories', ['name' => $name, 'code' => $code, 'unit' => $unit, 'generation' => 'assigned']);
    }

    public function test_assigned_allowances_and_deductions_are_paid_and_taken_on_payroll(): void
    {
        $this->category('Night shift', 'NS', 'night')->assertUnprocessable()->assertJsonValidationErrors(['generation' => 'Only monthly']);
        $responsibility = $this->category('Responsibility', 'RA')->assertCreated()->assertJsonPath('data.generation', 'assigned')->json('data.id');
        $housing = $this->category('Housing', 'HA')->assertCreated()->json('data.id');
        $this->inCompany(fn () => EmpLeave::create(['employee_id' => $this->nurse->id, 'leave_type_id' => LeaveType::where('code', 'AL')->value('id'), 'start_date' => '2026-11-16', 'end_date' => '2026-11-18',
            'requested_start_date' => '2026-11-16', 'requested_end_date' => '2026-11-18', 'days' => 3, 'days_requested' => 3, 'leave_year' => '2026', 'status' => 'reported']));

        $assign = fn (array $d) => $this->postJson("/api/v1/allowance/employees/{$this->nurse->id}/assigned", $d);
        $assign(['allowance_category_id' => $responsibility, 'amount' => 62000, 'start_date' => '2026-10-01'])->assertCreated()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.running', true);
        $assign(['allowance_category_id' => $responsibility, 'amount' => 1000, 'start_date' => '2026-12-01'])->assertUnprocessable()->assertJsonValidationErrors(['allowance_category_id' => 'already has']);
        $assign(['allowance_category_id' => $housing, 'amount' => 31000, 'start_date' => '2026-11-10'])->assertCreated();
        $this->postJson("/api/v1/allowance/employees/{$this->nurse->id}/claims", ['allowance_category_id' => $housing, 'date' => '2026-11-10', 'items' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['allowance_category_id' => 'assigned']);
        $this->getJson('/api/v1/allowance/assigned')->assertOk()->assertJsonCount(2, 'data')->assertJsonCount(2, 'categories');

        $deduct = fn (array $d) => $this->postJson("/api/v1/payroll/employees/{$this->nurse->id}/deductions", $d);
        $uniform = $deduct(['name' => 'Uniform', 'amount' => 20000, 'start_month' => '2026-11', 'months' => 2])->assertCreated()->assertJsonPath('data.end_month', '2026-12')->json('data.id');
        $deduct(['name' => 'Canteen', 'amount' => 5000, 'start_month' => '2026-12'])->assertCreated()->assertJsonPath('data.end_month', null);
        $this->getJson('/api/v1/payroll/deductions')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.monthly', 20000);

        $id = $this->postJson('/api/v1/payroll/sheets', ['year' => 2026, 'month' => 11])->assertCreated()->json('data.id');
        $row = $this->getJson("/api/v1/payroll/sheets/{$id}")->assertOk()->json('data.rows.0');
        $this->assertSame([69000, 20000], [$row['allowances'], $row['other_deductions']], '62,000 × 28/31 + 31,000 × 13/31; the uniform');
        $slip = $this->getJson("/api/v1/payroll/sheets/{$id}/payslips/{$row['id']}")->assertOk()->json('data');
        $this->assertContains('Responsibility (28 of 31 days, 3 leave days)', array_column($slip['earnings'], 'label'));
        $this->assertContains('Housing (13 of 31 days, 3 leave days)', array_column($slip['earnings'], 'label'));
        $this->assertContains('Uniform', array_column($slip['deductions'], 'label'));

        $this->postJson("/api/v1/payroll/deductions/{$uniform}/stop", ['month' => '2026-11'])->assertOk()->assertJsonPath('data.end_month', '2026-11');
        $this->assertFalse($this->inCompany(fn () => OtherDeduction::find($uniform)->appliesTo(2026, 12)));
        $this->deleteJson("/api/v1/payroll/deductions/{$uniform}")->assertUnprocessable();
        $this->actingAs($this->user)->getJson('/api/v1/payroll/deductions')->assertForbidden();
    }

    public function test_salary_adjustments_go_through_approval_and_change_the_contract(): void
    {
        $hr = $this->inCompany(fn () => tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('hr-officer'));
        $this->inCompany(fn () => WorkflowTemplate::create(['process' => 'salary-adjustment', 'is_active' => true])->steps()->create(['step_order' => 1, 'name' => 'Director', 'approver_type' => 'user', 'user_id' => $hr->id]));
        $adjust = fn (array $d) => $this->actingAs($this->admin)->postJson("/api/v1/payroll/employees/{$this->nurse->id}/adjustments", $d);

        $adjust(['new_salary' => 1000000, 'reason' => 'promotion'])->assertUnprocessable()->assertJsonValidationErrors(['new_salary' => 'same']);
        $id = $adjust(['new_salary' => 1150000, 'reason' => 'promotion'])->assertCreated()->assertJsonPath('data.type', 'increase')->assertJsonPath('data.percentage', 15)
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.waiting_for', 'Director')->json('data.id');
        $adjust(['new_salary' => 1200000, 'reason' => 'performance'])->assertUnprocessable()->assertJsonValidationErrors(['employee_id' => 'waiting']);

        $workflow = $this->inCompany(fn () => SalaryAdjustment::find($id)->workflow);
        $this->actingAs($hr)->getJson("/api/v1/workflow/requests/{$workflow->id}")->assertJsonPath('data.lines.0', 'Basic salary: 1,000,000.00 → 1,150,000.00 (+15%)');
        $this->postJson("/api/v1/workflow/requests/{$workflow->id}/approve")->assertOk();
        $this->assertSame(1150000.0, $this->inCompany(fn () => (float) $this->nurse->activeContract()->value('basic_salary')));
        $this->actingAs($this->user)->getJson('/api/v1/me/notifications')->assertJsonPath('data.0.title', 'Salary adjusted');

        $this->inCompany(fn () => WorkflowTemplate::where('process', 'salary-adjustment')->delete());
        $later = $adjust(['new_salary' => 1100000, 'reason' => 'correction', 'effective_date' => '2026-12-01'])->assertCreated()->assertJsonPath('data.type', 'decrease')
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.applied_at', null)->json('data.id');
        $this->assertSame(1150000.0, $this->inCompany(fn () => (float) $this->nurse->activeContract()->value('basic_salary')), 'not before the effective date');
        $this->travelTo('2026-12-01 01:00:00');
        $this->artisan('hr:salary-adjustments')->expectsOutputToContain('applied: 1')->assertSuccessful();
        $this->assertSame(1100000.0, $this->inCompany(fn () => (float) $this->nurse->activeContract()->value('basic_salary')));
        $this->deleteJson("/api/v1/payroll/adjustments/{$later}")->assertUnprocessable();
        $this->getJson('/api/v1/payroll/adjustments')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('reasons.promotion', 'Promotion');
    }

    public function test_a_rejected_deduction_is_never_taken(): void
    {
        $hr = $this->inCompany(fn () => tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('hr-officer'));
        $this->inCompany(fn () => WorkflowTemplate::create(['process' => 'other-deduction', 'is_active' => true])->steps()->create(['step_order' => 1, 'name' => 'Finance', 'approver_type' => 'user', 'user_id' => $hr->id]));
        $id = $this->actingAs($this->admin)->postJson("/api/v1/payroll/employees/{$this->nurse->id}/deductions", ['name' => 'Damage', 'amount' => 50000, 'start_month' => '2026-11', 'months' => 1])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $workflow = $this->inCompany(fn () => OtherDeduction::find($id)->workflow);
        $this->actingAs($hr)->postJson("/api/v1/workflow/requests/{$workflow->id}/reject", ['comment' => 'Not proven'])->assertOk();
        $this->assertSame(['rejected', 'Not proven'], $this->inCompany(fn () => [OtherDeduction::find($id)->status, OtherDeduction::find($id)->decision_comment]));

        $sheet = $this->actingAs($this->admin)->postJson('/api/v1/payroll/sheets', ['year' => 2026, 'month' => 11])->assertCreated()->json('data.id');
        $this->assertSame(0, $this->getJson("/api/v1/payroll/sheets/{$sheet}")->json('data.rows.0.other_deductions'));
        $this->deleteJson("/api/v1/payroll/deductions/{$id}")->assertNoContent();
    }
}
