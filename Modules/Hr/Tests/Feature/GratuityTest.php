<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\NatureOfContract;
use App\Models\PayrollSheet;
use App\Models\PayrollSheetDetail;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\JournalEntryLine;
use Modules\Accounting\Models\PayrollPostingSetting;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Hr\Actions\ManagePayroll;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class GratuityTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_gratuity_accrues_on_payroll_posts_and_is_settled(): void
    {
        $this->travelTo('2026-11-18 09:00:00');
        [$company, $admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'accounting'], ['slug' => 'payroll']]]);
        setPermissionsTeamId($company->id);
        [$stc, $perm, $emp, $staff, $acc, $bank] = app(CompanyContext::class)->run($company, function () use ($admin) {
            app(FiscalCalendar::class)->createYear('2026-01-01');
            $acc = [];
            foreach ([['1100', 'Bank', 'asset'], ['5200', 'Salaries', 'expense'], ['5250', 'Gratuity', 'expense'], ['2300', 'Net salaries payable', 'liability'],
                ['2200', 'PAYE payable', 'liability'], ['2210', 'NSSF payable', 'liability'], ['2400', 'Gratuity payable', 'liability']] as [$c, $n, $t]) {
                $acc[$c] = Account::create(['code' => $c, 'name' => $n, 'type' => $t, 'is_postable' => true, 'is_active' => true])->id;
            }
            $bank = CashBankAccount::create(['name' => 'Main', 'kind' => 'bank', 'account_id' => $acc['1100'], 'currency' => 'TZS', 'is_active' => true])->id;
            PayrollPostingSetting::current()->fill(['enabled' => true, 'salary_account_id' => $acc['5200'], 'net_pay_account_id' => $acc['2300'], 'paye_account_id' => $acc['2200'],
                'nssf_account_id' => $acc['2210'], 'nhif_account_id' => $acc['2210']])->save();
            $stc = NatureOfContract::create(['contract_name' => 'Specific time', 'code' => 'STC', 'employment_type' => 'full_time', 'is_permanent' => false, 'is_active' => true]);
            $perm = NatureOfContract::create(['contract_name' => 'Permanent', 'code' => 'PRM', 'employment_type' => 'full_time', 'is_permanent' => true, 'is_active' => true]);
            $dept = Department::create(['name' => 'Lab', 'is_active' => true]);
            $people = [];
            foreach ([['A', $stc], ['B', $perm]] as [$no, $type]) {
                $e = Employee::create(['employee_no' => $no, 'fname' => $no, 'lname' => 'X', 'gender' => 'Female', 'department_id' => $dept->id, 'employment_status' => 'confirmed', 'date_of_birth' => '1990-01-01']);
                $c = EmployeeContract::create(['employee_id' => $e->id, 'nature_of_contract_id' => $type->id, 'basic_salary' => 1000000, 'start_date' => '2026-01-01', 'is_active' => true, 'status' => 'active']);
                $people[] = [$e, $c];
            }

            return [$stc->id, $perm->id, $people[0][0], $people, $acc, $bank];
        });
        $payroll = function (string $month) use ($company, $staff, $admin) {
            return app(CompanyContext::class)->run($company, function () use ($month, $staff, $admin) {
                $sheet = PayrollSheet::create(['name' => "Month {$month}", 'month' => $month, 'year' => '2026', 'payroll_from_date' => "2026-{$month}-01", 'payroll_to_date' => "2026-{$month}-28", 'status' => 'pending', 'created_by' => $admin->id]);
                foreach ($staff as [$e, $c]) {
                    PayrollSheetDetail::create(['payroll_sheet_id' => $sheet->id, 'employee_id' => $e->id, 'contract_id' => $c->id, 'full_name' => $e->employee_no, 'basic_salary' => 1000000, 'actual_basic_salary' => 800000,
                        'gross_salary' => 1000000, 'paye' => 100000, 'nssf' => 100000, 'nssf_employer' => 0, 'nhif' => 0, 'nhif_employer' => 0, 'wcf' => 0, 'sdl' => 0, 'heslb' => 0, 'total_loan' => 0,
                        'total_deduction' => 200000, 'net_salary' => 800000]);
                }
                app(ManagePayroll::class)->approve($sheet);

                return $sheet;
            });
        };

        $payroll('09');
        $this->actingAs($admin)->getJson('/api/v1/payroll/gratuity')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/payroll/gratuity/settings')->assertJsonPath('data.enabled', false)->assertJsonPath('data.contract_types', [$stc]);
        $this->putJson('/api/v1/payroll/gratuity/settings', ['enabled' => true, 'rate_percent' => 25, 'base' => 'basic', 'contract_types' => [$stc],
            'expense_account_id' => $acc['5250'], 'liability_account_id' => $acc['2400']])->assertOk()->assertJsonPath('data.enabled', true);

        $payroll('10');
        $payroll('11');
        $this->getJson('/api/v1/payroll/gratuity')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.employee_id', $emp->id)->assertJsonPath('data.0.accrued', 400000)
            ->assertJsonPath('data.0.balance', 400000)->assertJsonPath('data.0.last_month', '2026-11')->assertJsonPath('totals.balance', 400000);
        $lines = app(CompanyContext::class)->run($company, fn () => JournalEntryLine::where('account_id', $acc['2400'])->sum('credit'));
        $this->assertEquals(400000, $lines);

        $this->postJson("/api/v1/payroll/gratuity/employees/{$emp->id}/settle", ['kind' => 'paid', 'date' => '2026-11-30'])->assertUnprocessable()->assertJsonValidationErrors(['cash_bank_account_id']);
        $this->postJson("/api/v1/payroll/gratuity/employees/{$emp->id}/settle", ['kind' => 'paid', 'date' => '2026-11-30', 'cash_bank_account_id' => $bank, 'reference' => 'TT-77'])
            ->assertCreated()->assertJsonPath('data.amount', 400000);
        $this->getJson("/api/v1/payroll/gratuity/employees/{$emp->id}")->assertJsonPath('data.balance', 0)->assertJsonCount(2, 'data.accruals')->assertJsonPath('data.settlements.0.kind', 'paid');
        $this->getJson('/api/v1/payroll/gratuity?owing=1')->assertJsonPath('meta.total', 0);
        $this->postJson("/api/v1/payroll/gratuity/employees/{$emp->id}/settle", ['kind' => 'forfeited', 'date' => '2026-11-30'])->assertUnprocessable();
        $this->assertEquals(0, app(CompanyContext::class)->run($company, fn () => JournalEntryLine::where('account_id', $acc['2400'])->sum(\Illuminate\Support\Facades\DB::raw('credit - debit'))));
        $this->assertNotNull($perm);
    }
}
