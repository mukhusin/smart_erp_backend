<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollSheet;
use App\Models\PayrollSheetDetail;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class PayrollComparisonTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_payroll_is_compared_with_the_month_before_by_employee_and_item(): void
    {
        [$company, $admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'payroll']]]);
        setPermissionsTeamId($company->id);
        [$oct, $nov] = app(CompanyContext::class)->run($company, function () {
            $ward = Department::create(['name' => 'Ward']);
            $staff = collect(['A1', 'B2', 'C3', 'D4'])->mapWithKeys(fn ($no) => [$no => Employee::create(['employee_no' => $no, 'fname' => $no, 'lname' => 'X', 'gender' => 'Female',
                'department_id' => $ward->id, 'emp_type' => 'full_time', 'employment_status' => 'confirmed'])]);
            $sheet = fn (int $month, string $status) => PayrollSheet::create(['name' => "Payroll {$month}/2026", 'month' => $month, 'year' => 2026, 'status' => $status,
                'payroll_from_date' => "2026-{$month}-01", 'payroll_to_date' => "2026-{$month}-28", 'number_of_days' => 30]);
            $slip = function (PayrollSheet $s, Employee $e, array $lines) {
                $gross = collect($lines)->where('group', 'earning')->sum('amount');
                $ded = collect($lines)->where('group', 'deduction')->sum('amount');
                PayrollSheetDetail::create(['payroll_sheet_id' => $s->id, 'employee_id' => $e->id, 'full_name' => $e->employee_no.' X', 'basic_salary' => 1000000,
                    'gross_salary' => $gross, 'total_deduction' => $ded, 'net_salary' => $gross - $ded, 'lines' => $lines]);
            };
            $basic = fn ($a = 1000000) => ['group' => 'earning', 'code' => 'BASIC', 'label' => 'Basic salary', 'amount' => $a];
            $paye = fn ($a) => ['group' => 'deduction', 'code' => 'PAYE', 'label' => 'PAYE', 'amount' => $a];
            $sep = $sheet(9, 'approved');
            $oct = $sheet(10, 'approved');
            $nov = $sheet(11, 'draft');
            $slip($sep, $staff['A1'], [$basic(1), $paye(0)]);
            $slip($oct, $staff['A1'], [$basic(), $paye(100000)]);
            $slip($oct, $staff['B2'], [$basic(), $paye(100000)]);
            $slip($oct, $staff['C3'], [$basic(), $paye(100000)]);
            $slip($nov, $staff['A1'], [$basic(), ['group' => 'earning', 'code' => 'OVERTIME', 'label' => 'Overtime (5 h)', 'amount' => 50000], $paye(110000)]);
            $slip($nov, $staff['B2'], [$basic(), $paye(100000)]);
            $slip($nov, $staff['D4'], [$basic(800000), $paye(70000)]);

            return [$oct, $nov];
        });

        $data = $this->actingAs($admin)->getJson("/api/v1/payroll/sheets/{$nov->id}/compare")->assertOk()->json('data');

        $this->assertSame($oct->id, $data['previous']['id']);
        $this->assertSame(['new' => 1, 'left' => 1, 'changed' => 1, 'same' => 1], $data['counts']);
        $this->assertEquals(2700000, $data['previous']['net']);
        $this->assertEquals(2570000, $data['current']['net']);
        $a1 = collect($data['employees'])->firstWhere('employee_no', 'A1');
        $this->assertSame('changed', $a1['status']);
        $this->assertEquals(40000, $a1['net_difference']);
        $this->assertSame('Overtime +50,000; PAYE +10,000', $a1['reason']);
        $this->assertSame('changed', $data['employees'][0]['status']);
        $overtime = collect($data['items'])->firstWhere('label', 'Overtime');
        $this->assertEquals(['previous' => 0, 'current' => 50000, 'difference' => 50000], array_intersect_key($overtime, array_flip(['previous', 'current', 'difference'])));
        $this->assertSame('left', collect($data['employees'])->firstWhere('employee_no', 'C3')['status']);

        $this->getJson("/api/v1/payroll/sheets/{$oct->id}/compare")->assertOk()->assertJsonPath('data.previous.period', '2026-09');
        $this->getJson("/api/v1/payroll/sheets/{$nov->id}/compare?with={$nov->id}")->assertNotFound();
        $this->get("/api/v1/payroll/sheets/{$nov->id}/compare/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
