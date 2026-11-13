<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\DailyAllowance;
use App\Models\Department;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeLoan;
use App\Models\Heslb;
use App\Models\LeaveType;
use App\Models\LoanHistory;
use App\Models\LoanProvider;
use App\Models\NatureOfContract;
use App\Models\Nhif;
use App\Models\Nssf;
use App\Models\Overtime;
use App\Models\PayrollDateSetting;
use App\Models\PayrollSheet;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Hr\Support\PayrollCalculator;
use Modules\Workflow\Models\WorkflowTemplate;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private User $user;

    /** @var array<string, Employee> */
    private array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-26 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'payroll'], ['slug' => 'leave'], ['slug' => 'self-service']]]);
        setPermissionsTeamId($this->company->id);
        $this->inCompany(function () {
            $ward = Department::create(['name' => 'Ward']);
            $stc = NatureOfContract::create(['contract_name' => 'Specific time', 'code' => 'STC', 'employment_type' => 'full_time', 'has_payroll' => true, 'has_annual_leave' => true]);
            $int = NatureOfContract::create(['contract_name' => 'Internship', 'code' => 'INT', 'employment_type' => 'internship', 'has_payroll' => false]);
            $this->user = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
            $make = function (string $no, NatureOfContract $type, float $basic, string $start, ?int $userId = null) use ($ward) {
                $e = $this->staff[$no] = Employee::create(['employee_no' => $no, 'fname' => $no, 'lname' => 'X', 'gender' => 'Female', 'department_id' => $ward->id, 'user_id' => $userId,
                    'emp_type' => 'full_time', 'emp_category' => 'Primary', 'employment_status' => 'confirmed', 'has_annual_leave' => true, 'employment_date' => $start]);
                EmployeeContract::create(['employee_id' => $e->id, 'nature_of_contract_id' => $type->id, 'contract_no' => $no, 'basic_salary' => $basic, 'period' => 12,
                    'start_date' => $start, 'end_date' => '2027-06-30', 'status' => 'active', 'is_active' => true]);

                return $e;
            };
            $a = $make('A', $stc, 1000000, '2025-01-01', $this->user->id);
            $make('B', $stc, 620000, '2026-11-10');
            $c = $make('C', $stc, 930000, '2025-01-01');
            $make('I', $int, 300000, '2026-01-01');
            Nssf::create(['employee_id' => $a->id, 'contribution_no' => 'N1', 'emp_rate' => 10, 'employer_rate' => 10, 'is_active' => true]);
            Nhif::create(['employee_id' => $a->id, 'contribution_no' => 'H1', 'emp_rate' => 3, 'employer_rate' => 3, 'is_active' => true]);
            Heslb::create(['employee_id' => $a->id, 'emp_rate' => 15, 'is_active' => true]);
            DailyAllowance::create(['employee_id' => $a->id, 'category' => 'Night allowance', 'duration' => 'night', 'items' => 5, 'amount_per_item' => 10000, 'amount' => 50000,
                'start_date' => '2026-11-03', 'end_date' => '2026-11-03', 'status' => 'approved', 'source' => 'auto']);
            DailyAllowance::create(['employee_id' => $a->id, 'category' => 'Night allowance', 'duration' => 'night', 'items' => 1, 'amount_per_item' => 10000, 'amount' => 10000,
                'start_date' => '2026-11-26', 'end_date' => '2026-11-26', 'status' => 'approved', 'source' => 'auto']);
            Overtime::create(['employee_id' => $a->id, 'department_id' => $ward->id, 'hours' => 5, 'minutes' => 0, 'overtime_minutes' => 300, 'amount' => 20000, 'task_title' => 'Cover',
                'date_of_task_done' => '2026-11-10', 'from_datetime' => '2026-11-10 17:00', 'to_datetime' => '2026-11-10 22:00', 'status' => 'approved', 'type' => 'claim']);
            $provider = LoanProvider::create(['name' => 'CRDB']);
            EmployeeLoan::create(['employee_id' => $a->id, 'loan_provider_id' => $provider->id, 'date_of_loan' => '2026-09-01', 'amount' => 300000, 'instalment' => 100000,
                'returned' => 0, 'remain' => 300000, 'is_active' => true, 'status' => 'enabled']);
            EmpLeave::create(['employee_id' => $c->id, 'leave_type_id' => LeaveType::where('code', 'UL')->value('id'), 'start_date' => '2026-11-16', 'end_date' => '2026-11-18',
                'requested_start_date' => '2026-11-16', 'requested_end_date' => '2026-11-18', 'days' => 3, 'days_requested' => 3, 'leave_year' => '2026', 'status' => 'reported']);
        });
    }

    private function inCompany(callable $fn): mixed
    {
        return app(CompanyContext::class)->run($this->company, $fn);
    }

    public function test_paye_bands(): void
    {
        $bands = PayrollDateSetting::TAX_BANDS;
        $this->assertSame([0.0, 4000.0, 20000.0, 118750.0, 128000.0, 278000.0], array_map(fn ($t) => PayrollCalculator::paye($t, $bands), [270000, 320000, 520000, 963000, 1000000, 1500000]));
    }

    public function test_monthly_payroll_is_worked_out_approved_and_locked(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/payroll/settings')->assertOk()->assertJsonPath('data.example_period', 'November 2026: 26 Oct – 25 Nov 2026');
        $id = $this->postJson('/api/v1/payroll/sheets', ['year' => 2026, 'month' => 11])->assertCreated()
            ->assertJsonPath('data.name', 'Payroll November 2026')->assertJsonPath('data.days', 31)->assertJsonPath('data.employees', 3)->json('data.id');
        $this->postJson('/api/v1/payroll/sheets', ['year' => 2026, 'month' => 11])->assertUnprocessable();

        $rows = collect($this->getJson("/api/v1/payroll/sheets/{$id}")->assertOk()->assertJsonPath('data.can_edit', true)->json('data.rows'))->keyBy('employee_no');
        $this->assertSame([1000000, 50000, 20000, 1070000, 107000, 30000, 150000, 118750, 100000, 505750, 564250],
            array_values(collect($rows['A'])->only(['basic', 'allowances', 'overtime', 'gross', 'nssf', 'nhif', 'heslb', 'paye', 'loans', 'deductions', 'net'])->all()));
        $this->assertSame([320000, 16, 4000, 316000], [$rows['B']['basic'], $rows['B']['days_paid'], $rows['B']['paye'], $rows['B']['net']], 'joined on 10 Nov: 16 of 31 days');
        $this->assertSame([840000, 3, 88000, 752000], [$rows['C']['basic'], $rows['C']['unpaid_days'], $rows['C']['paye'], $rows['C']['net']], '3 days without pay');
        $this->assertArrayNotHasKey('I', $rows->all(), 'internship contract has no payroll');

        $slip = $this->getJson("/api/v1/payroll/sheets/{$id}/payslips/{$rows['A']['id']}")->assertOk()->json('data');
        $this->assertSame(['Basic salary', 'Night allowance (5)', 'Overtime (5 h)'], array_column($slip['earnings'], 'label'));
        $this->assertSame(['PAYE', 'NSSF', 'NHIF', 'HESLB loan', 'Loan: CRDB'], array_column($slip['deductions'], 'label'));
        $this->assertSame([107000, 30000, 5350, 37450], array_column($slip['employer'], 'amount'));
        $this->postJson("/api/v1/payroll/sheets/{$id}/generate")->assertOk()->assertJsonPath('data.net', 1632250);
        $this->assertSame(1, $this->inCompany(fn () => DailyAllowance::whereNotNull('payroll_sheet_detail_id')->count()), 'only the allowance in the period');

        $approver = $this->inCompany(fn () => tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee'));
        $this->inCompany(fn () => WorkflowTemplate::create(['process' => 'payroll-sheet', 'is_active' => true])->steps()->create(['step_order' => 1, 'name' => 'Director', 'approver_type' => 'user', 'user_id' => $approver->id]));
        $this->postJson("/api/v1/payroll/sheets/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.waiting_for', 'Director');
        $this->postJson("/api/v1/payroll/sheets/{$id}/generate")->assertUnprocessable();
        $this->actingAs($this->user)->getJson('/api/v1/self/payslips')->assertOk()->assertJsonCount(0, 'data');

        $workflow = PayrollSheet::find($id)->workflow;
        $this->actingAs($approver)->getJson("/api/v1/workflow/requests/{$workflow->id}")->assertJsonPath('data.lines.2', 'Gross pay: 2,230,000.00 · Deductions: 597,750.00 · Net pay: 1,632,250.00');
        $this->postJson("/api/v1/workflow/requests/{$workflow->id}/approve")->assertOk();

        $this->assertSame('approved', PayrollSheet::find($id)->status);
        $loan = EmployeeLoan::first();
        $this->assertSame([200000.0, 100000.0], [(float) $loan->remain, (float) $loan->returned]);
        $this->assertSame('applied', LoanHistory::first()->status);
        $this->assertTrue((bool) Overtime::first()->is_paid);

        $this->actingAs($this->user)->getJson('/api/v1/me/notifications')->assertJsonPath('data.0.title', 'Payslip ready')
            ->assertJsonPath('data.0.message', 'Your payslip for November 2026 is ready. Net pay: 564,250.00.');
        $mine = $this->getJson('/api/v1/self/payslips')->assertJsonCount(1, 'data')->assertJsonPath('data.0.net', 564250)->json('data.0.id');
        $this->getJson("/api/v1/self/payslips/{$mine}")->assertOk()->assertJsonPath('data.employee.name', 'A X')->assertJsonPath('data.taxable', 963000);
        $this->getJson('/api/v1/payroll/sheets')->assertForbidden();

        $this->actingAs($this->admin)->getJson("/api/v1/payroll/sheets/{$id}/reports/paye")->assertOk()->assertJsonPath('data.title', 'PAYE (TRA)')
            ->assertJsonPath('data.totals.6', 210750)->assertJsonCount(3, 'data.rows')->assertJsonPath('data.missing_bank', 3);
        $this->getJson("/api/v1/payroll/sheets/{$id}/reports/nssf")->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.2', 'N1')->assertJsonPath('data.rows.0.6', 214000);
        $this->getJson("/api/v1/payroll/sheets/{$id}/reports/employer")->assertJsonPath('data.totals.7', 226200);
        $this->getJson("/api/v1/payroll/sheets/{$id}/reports/unknown")->assertNotFound();
        $csv = $this->get("/api/v1/payroll/sheets/{$id}/reports/bank/csv")->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('payroll-november-2026-bank-payment-list.csv', $csv->headers->get('Content-Disposition'));
        $this->assertStringStartsWith("\xEF\xBB\xBF\"Employee no\",Name,Bank", $csv->getContent());
        $this->assertStringContainsString('Total', $csv->getContent());
    }

    public function test_settings_and_tax_bands(): void
    {
        $body = $this->actingAs($this->admin)->getJson('/api/v1/payroll/settings')->json('data');
        $body['tax_bands'] = [['up_to' => 300000, 'rate' => 0], ['up_to' => null, 'rate' => 20]];
        $this->putJson('/api/v1/payroll/settings', $body)->assertOk()->assertJsonPath('data.tax_bands.1.rate', 20);
        $this->getJson('/api/v1/payroll/settings/paye?taxable=400000')->assertJsonPath('data.paye', 20000);
        $body['tax_bands'] = [['up_to' => 300000, 'rate' => 0], ['up_to' => 500000, 'rate' => 20]];
        $this->putJson('/api/v1/payroll/settings', $body)->assertUnprocessable()->assertJsonValidationErrors(['tax_bands']);
    }
}
