<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Nssf;
use App\Models\PayrollSheet;
use App\Models\PayrollSheetDetail;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class PayrollReportsTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_payroll_reports_by_month_department_scheme_and_bank(): void
    {
        [$company, $admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'payroll'], ['slug' => 'reports']]]);
        setPermissionsTeamId($company->id);
        $sheet = app(CompanyContext::class)->run($company, function () {
            $ward = Department::create(['name' => 'Ward']);
            $lab = Department::create(['name' => 'Lab']);
            $make = fn ($no, $dept) => Employee::create(['employee_no' => $no, 'fname' => $no, 'lname' => 'X', 'gender' => 'Female', 'department_id' => $dept->id, 'tin' => 'T'.$no,
                'emp_type' => 'full_time', 'employment_status' => 'confirmed']);
            $a = $make('A1', $ward);
            $b = $make('B1', $lab);
            Nssf::create(['employee_id' => $a->id, 'contribution_no' => 'N-A1', 'emp_rate' => 10, 'employer_rate' => 10, 'is_active' => true]);
            $march = PayrollSheet::create(['name' => 'Payroll March 2026', 'year' => '2026', 'month' => '3', 'payroll_from_date' => '2026-03-01', 'payroll_to_date' => '2026-03-31', 'status' => 'approved']);
            PayrollSheet::create(['name' => 'Payroll April 2026', 'year' => '2026', 'month' => '4', 'payroll_from_date' => '2026-04-01', 'payroll_to_date' => '2026-04-30', 'status' => 'draft']);
            foreach ([[$a, 1000000, 100000, 'CRDB', '0151'], [$b, 500000, 0, null, null]] as [$e, $gross, $nssf, $bank, $account]) {
                PayrollSheetDetail::create(['payroll_sheet_id' => $march->id, 'employee_id' => $e->id, 'full_name' => $e->employee_no.' X', 'basic_salary' => $gross, 'gross_salary' => $gross,
                    'paye' => $gross * 0.1, 'nssf' => $nssf, 'nssf_employer' => $nssf, 'wcf' => $gross * 0.005, 'sdl' => $gross * 0.035, 'total_deduction' => $gross * 0.1 + $nssf,
                    'net_salary' => $gross * 0.9 - $nssf, 'bank_name' => $bank, 'bank_account' => $account]);
            }

            return $march;
        });

        $keys = array_column($this->actingAs($admin)->getJson('/api/v1/reports/hr')->assertOk()->json('data'), 'key');
        $this->assertContains('payroll-statutory', $keys);
        $filters = collect($this->getJson('/api/v1/reports/hr')->json('data'))->firstWhere('key', 'payroll-bank')['filters'];
        $this->assertSame((string) $sheet->id, $filters[0]['default'], 'the latest approved payroll is chosen');

        $months = $this->getJson('/api/v1/reports/hr/payroll-months?year=2026')->assertOk()->json('data.rows');
        $this->assertEquals(['Payroll March 2026', 1500000, 150000, 200000, 7500, 52500, 1660000], [$months[0]['month'], $months[0]['gross'], $months[0]['paye'], $months[0]['nssf'], $months[0]['wcf'], $months[0]['sdl'], $months[0]['cost']]);
        $depts = collect($this->getJson("/api/v1/reports/hr/payroll-departments?sheet_id={$sheet->id}")->json('data.rows'))->keyBy('department');
        $this->assertEquals([1000000, 500000, 1500000], [$depts['Ward']['gross'], $depts['Lab']['gross'], $depts['Total']['gross']]);
        $nssf = $this->getJson("/api/v1/reports/hr/payroll-statutory?sheet_id={$sheet->id}&scheme=nssf")->json('data');
        $this->assertSame(1, count($nssf['rows']), 'only members who paid');
        $this->assertEquals(['A1', 'N-A1', 100000, 100000, 200000], [$nssf['rows'][0]['employee_no'], $nssf['rows'][0]['member_no'], $nssf['rows'][0]['employee_amount'], $nssf['rows'][0]['employer_amount'], $nssf['rows'][0]['total']]);
        $this->assertSame('TA1', $this->getJson("/api/v1/reports/hr/payroll-statutory?sheet_id={$sheet->id}&scheme=paye")->json('data.rows.0.member_no'));
        $bank = $this->getJson("/api/v1/reports/hr/payroll-bank?sheet_id={$sheet->id}")->json('data');
        $this->assertStringContainsString('1 without a bank account', $bank['subtitle']);
        $this->get("/api/v1/reports/hr/payroll-bank/csv?sheet_id={$sheet->id}")->assertOk();

        $dash = $this->getJson('/api/v1/payroll/dashboard')->assertOk()->json('data');
        $this->assertSame(['Payroll March 2026', 2, 'Mar 26'], [$dash['latest']['name'], $dash['latest']['staff'], $dash['months'][0]['label']]);
        $this->assertEquals([1660000, 160000, 200000], [$dash['latest']['cost'], $dash['latest']['employer'], $dash['latest']['nssf']]);
        $this->assertSame(['Ward', 'Lab'], array_column($dash['departments'], 'label'));
        $this->assertCount(1, $dash['months'], 'only approved payrolls');
        $this->assertSame([[$sheet->id, 'March 2026'], $sheet->id], [[$dash['sheets'][0]['id'], $dash['sheets'][0]['label']], $dash['focus_id']]);
        $this->assertSame($sheet->id, $this->getJson("/api/v1/payroll/dashboard?sheet_id={$sheet->id}")->assertOk()->json('data.latest.id'));
        $this->assertSame($sheet->id, $this->getJson('/api/v1/payroll/dashboard?sheet_id=999999')->assertOk()->json('data.focus_id'));

        $hod = User::factory()->create(['company_id' => $company->id]);
        $hod->assignRole('hod');
        $this->actingAs($hod)->getJson('/api/v1/reports/hr/payroll-bank')->assertNotFound();
        $this->actingAs($hod)->getJson('/api/v1/payroll/dashboard')->assertForbidden();
    }
}
