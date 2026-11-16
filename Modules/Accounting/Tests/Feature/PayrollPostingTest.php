<?php

namespace Modules\Accounting\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollSheet;
use App\Models\PayrollSheetDetail;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\PayrollPostingSetting;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Hr\Events\PayrollApproved;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class PayrollPostingTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_approved_payroll_posts_by_department_and_net_pay_is_paid(): void
    {
        $this->travelTo('2026-11-18 09:00:00');
        [$company] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'accounting'], ['slug' => 'payroll']]]);
        setPermissionsTeamId($company->id);
        [$user, $sheet, $bank] = app(CompanyContext::class)->run($company, function () use ($company) {
            Role::findOrCreate('acc', 'web')->syncPermissions(['accounting.view', 'accounting.journals', 'accounting.manage']);
            $user = tap(User::factory()->create(['company_id' => $company->id]))->assignRole('acc');
            app(FiscalCalendar::class)->createYear('2026-01-01');
            $acc = [];
            foreach ([['1100', 'Bank', 'asset'], ['3000', 'Fund', 'equity'], ['5200', 'Salaries', 'expense'], ['5210', 'Employer costs', 'expense'], ['2300', 'Net salaries payable', 'liability'],
                ['2200', 'PAYE payable', 'liability'], ['2210', 'NSSF payable', 'liability'], ['2100', 'Other payables', 'liability']] as [$c, $n, $t]) {
                $acc[$c] = Account::create(['code' => $c, 'name' => $n, 'type' => $t, 'is_postable' => true, 'is_active' => true])->id;
            }
            $bank = CashBankAccount::create(['name' => 'Main', 'kind' => 'bank', 'account_id' => $acc['1100'], 'currency' => 'TZS', 'is_active' => true])->id;
            $sheet = PayrollSheet::create(['name' => 'October 2026', 'month' => '10', 'year' => '2026', 'payroll_from_date' => '2026-10-01', 'payroll_to_date' => '2026-10-31', 'status' => 'approved', 'created_by' => $user->id]);
            foreach ([['A', 'Pharmacy', 1000000, 100000, 100000, 20000, 760000], ['B', 'Lab', 500000, 30000, 50000, 10000, 410000]] as [$no, $dept, $gross, $paye, $nssf, $nhif, $net]) {
                $d = Department::create(['name' => $dept, 'is_active' => true]);
                $e = Employee::create(['employee_no' => $no, 'fname' => $no, 'lname' => 'X', 'gender' => 'Female', 'department_id' => $d->id, 'employment_status' => 'confirmed', 'date_of_birth' => '1990-01-01']);
                PayrollSheetDetail::create(['payroll_sheet_id' => $sheet->id, 'employee_id' => $e->id, 'full_name' => "{$no} X", 'gross_salary' => $gross, 'paye' => $paye, 'nssf' => $nssf,
                    'nssf_employer' => $nssf, 'nhif' => $nhif, 'nhif_employer' => 0, 'wcf' => 0, 'sdl' => 0, 'heslb' => 0, 'total_loan' => 0,
                    'total_deduction' => $gross - $net, 'net_salary' => $net]);
            }
            PayrollPostingSetting::current()->fill(['enabled' => true, 'salary_account_id' => $acc['5200'], 'employer_account_id' => $acc['5210'], 'net_pay_account_id' => $acc['2300'],
                'paye_account_id' => $acc['2200'], 'nssf_account_id' => $acc['2210'], 'nhif_account_id' => $acc['2210'], 'other_account_id' => $acc['2100']])->save();

            return [$user, $sheet, $bank];
        });

        $this->actingAs($user)->getJson("/api/v1/accounting/payroll/{$sheet->id}")->assertJsonPath('data.can.post', true)->assertJsonPath('data.net', 1170000);
        app(CompanyContext::class)->run($company, fn () => event(new PayrollApproved($sheet, $user)));
        $state = $this->getJson("/api/v1/accounting/payroll/{$sheet->id}")->assertJsonPath('data.error', null)->assertJsonPath('data.can.post', false)->assertJsonPath('data.can.pay', true)->json('data');
        $journal = app(CompanyContext::class)->run($company, fn () => JournalEntry::with('lines')->find($state['posted']['id']));
        $this->assertSame(1650000.0, (float) $journal->total);
        $this->assertSame(2, $journal->lines->where('account_id', $journal->lines->firstWhere('description', 'Gross salaries')->account_id)->whereNotNull('department_id')->count());
        $this->assertSame(20000.0, (float) $journal->lines->where('description', 'Other deductions payable')->sum('credit'));
        $this->assertSame(1170000.0, (float) $journal->lines->where('description', 'Net salaries payable')->sum('credit'));
        $this->postJson("/api/v1/accounting/payroll/{$sheet->id}/post")->assertUnprocessable();
        $this->postJson("/api/v1/accounting/payroll/{$sheet->id}/pay", ['cash_bank_account_id' => $bank, 'date' => '2026-11-01'])->assertOk();
        $this->getJson("/api/v1/accounting/payroll/{$sheet->id}")->assertJsonPath('data.can.pay', false);
        $tb = collect($this->getJson('/api/v1/accounting/reports/trial-balance')->json('data'));
        $this->assertNotEmpty($tb);
    }
}
