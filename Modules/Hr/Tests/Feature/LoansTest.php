<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeLoan;
use App\Models\EmployeeMonthlyAdvance;
use App\Models\NatureOfContract;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Workflow\Models\WorkflowTemplate;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class LoansTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private User $user;

    private Employee $nurse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-13 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'loans'], ['slug' => 'payroll'], ['slug' => 'self-service']]]);
        setPermissionsTeamId($this->company->id);
        $this->inCompany(function () {
            $ward = Department::create(['name' => 'Ward']);
            $type = NatureOfContract::create(['contract_name' => 'Specific time', 'code' => 'STC', 'employment_type' => 'full_time', 'has_payroll' => true]);
            $this->user = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
            $this->nurse = Employee::create(['employee_no' => 'N1', 'fname' => 'Asha', 'lname' => 'X', 'gender' => 'Female', 'department_id' => $ward->id, 'user_id' => $this->user->id,
                'emp_type' => 'full_time', 'emp_category' => 'Primary', 'employment_status' => 'confirmed', 'employment_date' => '2025-01-01']);
            EmployeeContract::create(['employee_id' => $this->nurse->id, 'nature_of_contract_id' => $type->id, 'contract_no' => 'N1', 'basic_salary' => 1000000, 'period' => 12,
                'start_date' => '2025-01-01', 'end_date' => '2027-06-30', 'status' => 'active', 'is_active' => true]);
        });
    }

    private function inCompany(callable $fn): mixed
    {
        return app(CompanyContext::class)->run($this->company, $fn);
    }

    private function payroll(): int
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/payroll/sheets', ['year' => 2026, 'month' => 11])->assertCreated()->json('data.id');
    }

    public function test_loans_are_repaid_by_payroll_and_can_be_paused(): void
    {
        $crdb = $this->actingAs($this->admin)->postJson('/api/v1/loans/providers', ['name' => 'CRDB Bank', 'short_name' => 'CRDB'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/loans/providers', ['name' => 'CRDB Bank'])->assertUnprocessable();
        $loan = fn (array $d) => $this->postJson("/api/v1/loans/employees/{$this->nurse->id}/loans", $d + ['loan_provider_id' => $crdb, 'date_of_loan' => '2026-11-01']);
        $loan(['amount' => 100000, 'instalment' => 200000])->assertUnprocessable()->assertJsonValidationErrors(['instalment']);
        $id = $loan(['amount' => 300000, 'instalment' => 100000])->assertCreated()->assertJsonPath('data.status', 'enabled')->assertJsonPath('data.remain', 300000)->json('data.id');
        $loan(['amount' => 50000, 'instalment' => 10000])->assertUnprocessable()->assertJsonValidationErrors(['loan_provider_id' => 'already has an open loan']);
        $this->getJson("/api/v1/loans/loans/{$id}")->assertOk()->assertJsonPath('data.schedule', [
            ['month' => '2026-11', 'instalment' => 100000, 'remaining' => 200000], ['month' => '2026-12', 'instalment' => 100000, 'remaining' => 100000], ['month' => '2027-01', 'instalment' => 100000, 'remaining' => 0],
        ]);

        $this->postJson("/api/v1/loans/loans/{$id}/pause", ['pause' => true])->assertOk()->assertJsonPath('data.status', 'paused');
        $sheet = $this->payroll();
        $this->assertSame(0.0, (float) $this->getJson("/api/v1/payroll/sheets/{$sheet}")->json('data.rows.0.loans'), 'paused loans are not deducted');
        $this->postJson("/api/v1/loans/loans/{$id}/pause", ['pause' => false])->assertOk()->assertJsonPath('data.status', 'enabled');
        $this->postJson("/api/v1/payroll/sheets/{$sheet}/generate")->assertOk();
        $this->assertSame(100000.0, (float) $this->getJson("/api/v1/payroll/sheets/{$sheet}")->json('data.rows.0.loans'));
        $this->getJson("/api/v1/loans/loans/{$id}")->assertJsonPath('data.schedule.0.month', '2026-12')->assertJsonPath('data.repayments.0.status', 'pending');

        $this->postJson("/api/v1/payroll/sheets/{$sheet}/submit")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->getJson("/api/v1/loans/loans/{$id}")->assertJsonPath('data.loan.returned', 100000)->assertJsonPath('data.loan.progress', 33)->assertJsonPath('data.repayments.0.status', 'applied');
        $this->putJson("/api/v1/loans/loans/{$id}", ['loan_provider_id' => $crdb, 'amount' => 50000, 'instalment' => 10000, 'date_of_loan' => '2026-11-01'])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount' => 'repaid']);
        $this->putJson("/api/v1/loans/loans/{$id}", ['loan_provider_id' => $crdb, 'amount' => 400000, 'instalment' => 150000, 'date_of_loan' => '2026-11-01'])
            ->assertOk()->assertJsonPath('data.remain', 300000);
        $this->deleteJson("/api/v1/loans/loans/{$id}")->assertUnprocessable();
        $this->getJson('/api/v1/loans/loans')->assertJsonPath('meta.outstanding', 300000)->assertJsonPath('meta.monthly', 150000);
        $this->getJson('/api/v1/loans/providers')->assertJsonPath('data.0.outstanding', 300000);
        $this->actingAs($this->user)->getJson('/api/v1/self/loans')->assertOk()->assertJsonPath('data.loans.0.loan.provider.name', 'CRDB Bank')->assertJsonPath('data.advance_limit', 500000);
        $this->assertSame(0, $this->getJson('/api/v1/self/loans')->json('data.loans.0.loan.not_deducted'));
        $this->inCompany(fn () => \App\Models\EmployeeLoan::whereKey($id)->update(['status' => 'disabled', 'returned' => 250000, 'remain' => 0]));
        $this->getJson('/api/v1/self/loans')->assertJsonPath('data.loans.0.loan.status_label', 'Repaid')->assertJsonPath('data.loans.0.loan.not_deducted', 150000);
        $this->getJson('/api/v1/loans/loans')->assertForbidden();
    }

    public function test_salary_advances_and_approvals(): void
    {
        $approver = $this->inCompany(fn () => tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee'));
        $this->inCompany(function () use ($approver) {
            foreach (['salary-advance', 'employee-loan'] as $process) {
                WorkflowTemplate::where('process', $process)->get()->each->delete();
                WorkflowTemplate::create(['process' => $process, 'is_active' => true])->steps()->create(['step_order' => 1, 'name' => 'Finance', 'approver_type' => 'user', 'user_id' => $approver->id]);
            }
        });
        $this->actingAs($this->user)->postJson('/api/v1/self/advances', ['amount' => 600000, 'reason' => 'School fees'])->assertUnprocessable()->assertJsonValidationErrors(['amount' => '50%']);
        $id = $this->postJson('/api/v1/self/advances', ['amount' => 400000, 'reason' => 'School fees'])->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.waiting_for', 'Finance')->json('data.id');
        $this->postJson('/api/v1/self/advances', ['amount' => 1000, 'reason' => 'More'])->assertUnprocessable()->assertJsonValidationErrors(['amount' => 'already have']);

        $workflow = EmployeeMonthlyAdvance::find($id)->workflow;
        $this->actingAs($approver)->getJson("/api/v1/workflow/requests/{$workflow->id}")->assertJsonPath('data.lines.0', 'Amount: 400,000.00 (40% of basic salary)');
        $this->postJson("/api/v1/workflow/requests/{$workflow->id}/approve")->assertOk();
        $this->assertSame('approved', EmployeeMonthlyAdvance::find($id)->status);
        $this->actingAs($this->user)->getJson('/api/v1/me/notifications')->assertJsonPath('data.0.title', 'Salary advance approved');

        $sheet = $this->payroll();
        $this->getJson("/api/v1/payroll/sheets/{$sheet}")->assertJsonPath('data.rows.0.loans', 400000);
        $this->getJson('/api/v1/loans/advances?month=2026-11')->assertJsonPath('data.0.payroll', 'Payroll November 2026')->assertJsonPath('data.0.can_cancel', false)->assertJsonPath('meta.total', 400000);
        $this->inCompany(fn () => WorkflowTemplate::where('process', 'payroll-sheet')->delete());
        $this->postJson("/api/v1/payroll/sheets/{$sheet}/submit")->assertOk();
        $this->assertSame('deducted', EmployeeMonthlyAdvance::find($id)->status);

        $loanId = $this->postJson('/api/v1/loans/providers', ['name' => 'Company'])->json('data.id');
        $pending = $this->postJson("/api/v1/loans/employees/{$this->nurse->id}/loans", ['loan_provider_id' => $loanId, 'amount' => 200000, 'instalment' => 50000, 'date_of_loan' => '2026-12-01'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->actingAs($approver)->postJson('/api/v1/workflow/requests/'.EmployeeLoan::find($pending)->workflow->id.'/approve')->assertOk();
        $this->assertSame('enabled', EmployeeLoan::find($pending)->status);
        $this->assertContains('Your Company loan of 200,000.00 is recorded: 50,000.00 a month from your salary.', array_column($this->actingAs($this->user)->getJson('/api/v1/me/notifications')->json('data'), 'message'));
    }
}
