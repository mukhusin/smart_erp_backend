<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\NatureOfContract;
use App\Models\PayrollSheet;
use App\Models\PayrollSheetDetail;
use App\Models\UploadedPayrollRow;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class SalaryFileTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private User $user;

    private Employee $asha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-10 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'payroll'], ['slug' => 'self-service']]]);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->run($this->company, function () {
            $ward = Department::create(['name' => 'Ward']);
            $type = NatureOfContract::create(['contract_name' => 'Specific time', 'code' => 'STC', 'employment_type' => 'full_time', 'has_payroll' => true]);
            $this->user = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
            foreach ([['ST1741', 'Asha', $this->user->id], ['ST1800', 'Juma', null]] as [$no, $name, $userId]) {
                $e = Employee::create(['employee_no' => $no, 'fname' => $name, 'lname' => 'X', 'gender' => 'Female', 'department_id' => $ward->id, 'user_id' => $userId, 'tin' => $no === 'ST1741' ? '156071846' : null,
                    'emp_type' => 'full_time', 'employment_status' => 'confirmed', 'employment_date' => '2020-01-01']);
                EmployeeContract::create(['employee_id' => $e->id, 'nature_of_contract_id' => $type->id, 'contract_no' => $no, 'basic_salary' => 910000, 'period' => 12,
                    'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'active', 'is_active' => true]);
                $no === 'ST1741' && $this->asha = $e;
            }
        });
        $this->giveActiveContracts($this->company);
    }

    private function csv(array $rows): UploadedFile
    {
        $lines = ['ST_NO,NAME,DESG,BASIC_SALARY,GROSS_SALARY,PAYE,NSSF,NHIF_3_PERCENT,E_FUND,NMB_LOAN,NET_PAY,NET_TO_BANK,NSSF_20_PERCENT'];
        foreach ($rows as $r) {
            $lines[] = implode(',', $r);
        }

        return UploadedFile::fake()->createWithContent('april.csv', implode("\n", $lines));
    }

    public function test_salary_file_upload_payslips_and_template(): void
    {
        $template = $this->actingAs($this->admin)->get('/api/v1/payroll/salary-files/template')->assertOk()->getContent();
        $this->assertStringContainsString('ST_NO,NAME,TIN,NSSFA_CNO,DESG,BASIC_SALARY', $template);
        $this->assertStringContainsString('ST1741,"Asha X",156071846', $template);

        $row = ['1741', 'Asha X', 'Supplies Officer', '910000', '910000', '82750', '91000', '27300', '5000', '"298,957.13"', '404992.87', '404992.87', '182000'];
        $this->postJson('/api/v1/payroll/salary-files', ['year' => 2026, 'month' => 4, 'file' => $this->csv([$row, ['9999', 'Nobody', '', '1', '1', '0', '0', '0', '0', '0', '1', '1', '0']])])
            ->assertUnprocessable()->assertJsonValidationErrors(['file' => 'no employee with number "9999"']);
        $id = $this->postJson('/api/v1/payroll/salary-files', ['year' => 2026, 'month' => 4, 'file' => $this->csv([$row])])->assertCreated()
            ->assertJsonPath('data.rows', 1)->assertJsonPath('data.net_total', 404992.87)->json('data.id');
        $this->postJson('/api/v1/payroll/salary-files', ['year' => 2026, 'month' => 4, 'file' => $this->csv([$row])])->assertUnprocessable()->assertJsonValidationErrors(['month']);
        $id = $this->postJson('/api/v1/payroll/salary-files', ['year' => 2026, 'month' => 4, 'replace' => true, 'file' => $this->csv([$row])])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/payroll/salary-files')->assertOk()->assertJsonPath('data.0.label', 'April 2026')->assertJsonPath('meta.total', 1);
        $rowId = $this->getJson("/api/v1/payroll/salary-files/{$id}/rows?search=Asha")->assertOk()->assertJsonPath('data.0.employee_no', 'ST1741')->json('data.0.id');
        $this->get("/api/v1/payroll/salary-files/{$id}/rows/{$rowId}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $sheet = app(CompanyContext::class)->run($this->company, function () {
            $s = PayrollSheet::create(['name' => 'Payroll March 2026', 'year' => '2026', 'month' => '3', 'payroll_from_date' => '2026-02-26', 'payroll_to_date' => '2026-03-25', 'status' => 'approved']);
            $s2 = PayrollSheet::create(['name' => 'Payroll April 2026', 'year' => '2026', 'month' => '4', 'payroll_from_date' => '2026-03-26', 'payroll_to_date' => '2026-04-25', 'status' => 'approved']);
            foreach ([$s, $s2] as $sheet) {
                PayrollSheetDetail::create(['payroll_sheet_id' => $sheet->id, 'employee_id' => $this->asha->id, 'full_name' => 'Asha X', 'basic_salary' => 910000, 'gross_salary' => 910000,
                    'total_deduction' => 206050, 'net_salary' => 703950, 'lines' => [['group' => 'earning', 'code' => 'BASIC', 'label' => 'Basic salary', 'amount' => 910000],
                        ['group' => 'deduction', 'code' => 'PAYE', 'label' => 'PAYE', 'amount' => 82750]]]);
            }

            return $s;
        });

        $months = collect($this->actingAs($this->user)->getJson('/api/v1/self/payslips')->assertOk()->json('data'));
        $this->assertSame([['upload', 4, 404992.87], ['payroll', 3, 703950]], $months->map(fn ($m) => [$m['source'], $m['month'], $m['net']])->all(), 'the salary file wins for April');
        $this->getJson("/api/v1/self/uploaded-payslips/{$rowId}")->assertOk()->assertJsonPath('data.net', 404992.87)
            ->assertJsonPath('data.deductions.4', ['label' => 'NMB Loan', 'amount' => 298957.13])->assertJsonPath('data.source', 'upload');
        $this->get("/api/v1/self/uploaded-payslips/{$rowId}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $detail = PayrollSheetDetail::where('payroll_sheet_id', $sheet->id)->value('id');
        $this->get("/api/v1/self/payslips/{$detail}/pdf")->assertOk();

        $other = app(CompanyContext::class)->run($this->company, fn () => tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee'));
        app(CompanyContext::class)->run($this->company, fn () => Employee::where('employee_no', 'ST1800')->update(['user_id' => $other->id]));
        $this->actingAs($other)->getJson("/api/v1/self/uploaded-payslips/{$rowId}")->assertNotFound();
        $this->actingAs($this->user)->postJson('/api/v1/payroll/salary-files', ['year' => 2026, 'month' => 5, 'file' => $this->csv([$row])])->assertForbidden();

        $this->actingAs($this->admin)->deleteJson("/api/v1/payroll/salary-files/{$id}")->assertNoContent();
        $this->assertSame(0, UploadedPayrollRow::count());
    }
}
