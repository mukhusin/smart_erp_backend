<?php

namespace Modules\Reporting\Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\PayrollPostingSetting;
use Modules\Accounting\Services\FiscalCalendar;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class ExpenseRatiosTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_ratios_report_and_dashboard_with_programme_share(): void
    {
        $this->travelTo('2026-11-18 09:00:00');
        $modules = array_map(fn ($s) => ['slug' => $s], ['hr', 'admin', 'accounting', 'reports', 'self-service']);
        [$company, $admin] = $this->createCompany('Alpha', ['modules' => $modules]);
        setPermissionsTeamId($company->id);
        [$dg, $accountant, $ward, $office] = app(CompanyContext::class)->run($company, function () use ($company) {
            app(FiscalCalendar::class)->createYear('2026-07-01');
            $acc = [];
            foreach ([['1100', 'Bank', 'asset'], ['4100', 'Patient fees', 'revenue'], ['5200', 'Salaries', 'expense'], ['5100', 'Supplies', 'expense']] as [$c, $n, $t]) {
                $acc[$c] = Account::create(['code' => $c, 'name' => $n, 'type' => $t, 'is_postable' => true, 'is_active' => true])->id;
            }
            PayrollPostingSetting::current()->fill(['salary_account_id' => $acc['5200']])->save();
            $ward = Department::create(['name' => 'Ward', 'is_active' => true]);
            $section = Department::create(['name' => 'Theatre', 'parent_id' => $ward->id, 'is_active' => true]);
            $office = Department::create(['name' => 'Office', 'is_active' => true]);
            $entry = JournalEntry::create(['reference_no' => 'JV-1', 'date' => '2026-10-15', 'description' => 'Month', 'status' => 'posted', 'entry_type' => 'manual', 'total' => 1000000]);
            foreach ([[$acc['1100'], 1000000, 0, null], [$acc['4100'], 0, 1000000, null], [$acc['5200'], 500000, 0, $section->id], [$acc['5100'], 200000, 0, $office->id],
                [$acc['5100'], 100000, 0, null], [$acc['1100'], 0, 800000, null]] as [$a, $dr, $cr, $dep]) {
                $entry->lines()->create(['account_id' => $a, 'debit' => $dr, 'credit' => $cr, 'department_id' => $dep]);
            }

            return [tap(User::factory()->create(['company_id' => $company->id]))->assignRole('director-general'),
                tap(User::factory()->create(['company_id' => $company->id]))->assignRole('finance-manager'), $ward, $office];
        });

        $report = fn () => collect($this->actingAs($accountant)->getJson('/api/v1/reports/finance/expense-ratios?from=2026-10-01&to=2026-10-31')->assertOk()->json('data.rows'))->keyBy('item');
        $rows = $report();
        $this->assertEquals(800000, $rows['Expenditure']['amount']);
        $this->assertEquals(80, $rows['Expenditure']['of_revenue']);
        $this->assertEquals(62.5, $rows['Staff costs (salaries and employer contributions)']['share']);
        $this->assertArrayNotHasKey('BY PURPOSE', $rows->all());
        $fund = app(CompanyContext::class)->run($company, fn () => \Modules\Accounting\Models\FundSource::create(['code' => 'USAID', 'name' => 'USAID', 'kind' => 'donor', 'is_active' => true])->id);
        $this->actingAs($accountant)->getJson("/api/v1/reports/finance/expense-ratios?from=2026-10-01&to=2026-10-31&fund_source_id={$fund}")->assertOk()
            ->assertJsonPath('data.rows.2.amount', 0)->assertJsonPath('data.subtitle', '1 Oct 2026 – 31 Oct 2026 · USAID');

        $this->actingAs($accountant)->putJson('/api/v1/accounting/settings', ['use_gfs' => false, 'budget_phasing' => 'quarter', 'budget_control' => 'warn', 'expense_classes' => true, 'programme_target' => 75])
            ->assertOk()->assertJsonPath('data.expense_classes', true);
        $this->actingAs($admin)->putJson("/api/v1/hr/structure/departments/{$ward->id}", ['name' => 'Ward', 'expense_class' => 'programme'])->assertOk()->assertJsonPath('data.expense_class', 'programme');
        $this->putJson("/api/v1/hr/structure/departments/{$office->id}", ['name' => 'Office', 'expense_class' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors(['expense_class']);
        $this->putJson("/api/v1/hr/structure/departments/{$office->id}", ['name' => 'Office', 'expense_class' => 'admin'])->assertOk();

        $rows = $report();
        $this->assertEquals(62.5, $rows['Programme']['share']);
        $this->assertEquals(25, $rows['Administration']['share']);
        $this->assertEquals(12.5, $rows['Not classed']['share']);
        $this->assertEquals(75, $rows['Programme target']['share']);

        $this->actingAs($dg)->getJson('/api/v1/reports/executive')->assertOk()->assertJsonPath('data.ratios.programme_ratio', 62.5)
            ->assertJsonPath('data.ratios.operating_ratio', 80)->assertJsonPath('data.ratios.programme_target', 75)->assertJsonCount(12, 'data.ratios.trend');
        $this->assertContains('expense-classes', $this->actingAs($dg)->getJson('/api/v1/me')->json('data.features'));
    }
}
