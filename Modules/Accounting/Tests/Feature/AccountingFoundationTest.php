<?php

namespace Modules\Accounting\Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Accounting\Models\Account;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class AccountingFoundationTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private User $accountant;

    private User $clerk;

    private Department $pharmacy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-18 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'accounting']]]);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->run($this->company, function () {
            Role::findOrCreate('accountant', 'web')->syncPermissions(['accounting.view', 'accounting.journals', 'accounting.manage']);
            $this->accountant = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('accountant');
            $this->clerk = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
            $this->pharmacy = Department::create(['name' => 'Pharmacy', 'is_active' => true]);
            if (! WorkflowTemplate::where('process', 'manual-journal')->exists()) {
                $t = WorkflowTemplate::create(['process' => 'manual-journal', 'company_branch_id' => null, 'is_active' => true]);
                $t->steps()->create(['step_order' => 1, 'name' => 'Chief accountant', 'approver_type' => 'role', 'role_id' => Role::where('name', 'company-admin')->value('id'), 'allow_send_back' => true]);
            }
        });
    }

    private function chart(): array
    {
        $this->actingAs($this->accountant);
        $csv = "code,name,type,parent_code,gfs_code,postable,budgetable,description\n1000,Assets,asset,,,no,no,\n1100,CRDB bank,asset,1000,,yes,no,\n3000,Capital,equity,,,yes,no,\n4100,Patient fees,revenue,,,yes,yes,\n5100,Medical supplies,expense,,22010101,yes,yes,\n";
        $this->post('/api/v1/accounting/accounts/import', ['file' => UploadedFile::fake()->createWithContent('coa.csv', $csv)])->assertOk()->assertJsonPath('data.saved', 5);

        return Account::pluck('id', 'code')->all();
    }

    public function test_settings_fiscal_year_and_chart_import(): void
    {
        $this->actingAs($this->accountant)->getJson('/api/v1/accounting/settings')->assertOk()
            ->assertJsonPath('data.use_gfs', false)->assertJsonPath('data.budget_phasing', 'quarter')->assertJsonPath('data.budget_control', 'warn');
        $this->putJson('/api/v1/accounting/settings', ['use_gfs' => false, 'budget_phasing' => 'month', 'budget_control' => 'warn'])->assertOk()->assertJsonPath('data.budget_phasing', 'month');

        $this->postJson('/api/v1/accounting/fiscal-years', ['start_date' => '2026-07-01'])->assertCreated()->assertJsonPath('data.name', '2026/27')
            ->assertJsonCount(12, 'data.periods')->assertJsonPath('data.periods.0.name', 'Jul 2026')->assertJsonPath('data.periods.11.end_date', '2027-06-30');
        $this->postJson('/api/v1/accounting/fiscal-years', ['start_date' => '2027-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['start_date' => 'overlaps']);
        $this->getJson('/api/v1/accounting/fiscal-years')->assertJsonPath('next_start', '2027-07-01');

        $bad = "code,name,type,parent_code\n1000,Assets,asset,\n1000,Again,asset,\n2000,X,wrong,9999\n";
        $this->post('/api/v1/accounting/accounts/import', ['file' => UploadedFile::fake()->createWithContent('bad.csv', $bad)])->assertOk()
            ->assertJsonPath('data.saved', 0)->assertJsonPath('data.invalid', 3)->assertJsonPath('data.rows.2.errors.0', 'Type must be asset, liability, equity, revenue or expense.');
        $ids = $this->chart();
        $this->getJson('/api/v1/accounting/accounts')->assertJsonCount(5, 'data')->assertJsonPath('data.1.parent', '1000 Assets')->assertJsonPath('data.4.gfs_code', '22010101')
            ->assertJsonPath('data.4.is_budgetable', true)->assertJsonPath('data.0.is_postable', false);
        $this->putJson("/api/v1/accounting/accounts/{$ids['1000']}", ['code' => '1000', 'name' => 'Assets', 'type' => 'asset', 'parent_id' => $ids['1100'], 'is_postable' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
        $this->postJson('/api/v1/accounting/accounts', ['code' => '1100', 'name' => 'Dup', 'type' => 'asset'])->assertUnprocessable()->assertJsonValidationErrors(['code']);

        $this->postJson('/api/v1/accounting/fund-sources', ['code' => 'OWN', 'name' => 'Own revenue', 'kind' => 'own'])->assertCreated();
        $this->postJson('/api/v1/accounting/cash-bank', ['name' => 'CRDB main', 'kind' => 'bank', 'account_id' => $ids['5100']])->assertUnprocessable()->assertJsonValidationErrors(['account_id']);
        $this->postJson('/api/v1/accounting/cash-bank', ['name' => 'CRDB main', 'kind' => 'bank', 'account_id' => $ids['1100'], 'account_number' => '0150'])->assertCreated();

        $this->actingAs($this->clerk)->getJson('/api/v1/accounting/accounts')->assertForbidden();
    }

    public function test_manual_journal_is_approved_posted_reported_and_reversed(): void
    {
        $this->actingAs($this->accountant)->postJson('/api/v1/accounting/fiscal-years', ['start_date' => '2026-01-01'])->assertCreated();
        $ids = $this->chart();
        $fund = $this->postJson('/api/v1/accounting/fund-sources', ['code' => 'OWN', 'name' => 'Own revenue', 'kind' => 'own'])->json('data.id');

        $id = $this->postJson('/api/v1/accounting/journals', ['date' => '2026-11-10', 'description' => 'Opening capital', 'lines' => [
            ['account_id' => $ids['1100'], 'debit' => 1000000], ['account_id' => $ids['3000'], 'credit' => 900000],
        ]])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/accounting/journals/{$id}/submit")->assertUnprocessable()->assertJsonValidationErrors(['lines' => 'must be equal']);
        $this->putJson("/api/v1/accounting/journals/{$id}", ['date' => '2026-11-10', 'description' => 'Opening capital', 'lines' => [
            ['account_id' => $ids['1000'], 'debit' => 1000000], ['account_id' => $ids['3000'], 'credit' => 1000000],
        ]])->assertOk();
        $this->postJson("/api/v1/accounting/journals/{$id}/submit")->assertUnprocessable()->assertJsonValidationErrors(['lines.0' => 'heading']);
        $this->putJson("/api/v1/accounting/journals/{$id}", ['date' => '2026-11-10', 'description' => 'Opening capital', 'lines' => [
            ['account_id' => $ids['1100'], 'debit' => 1000000], ['account_id' => $ids['3000'], 'credit' => 1000000],
        ]])->assertOk();
        $this->postJson("/api/v1/accounting/journals/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->getJson("/api/v1/accounting/journals/{$id}")->assertJsonPath('data.waiting_for', 'Chief accountant')->assertJsonPath('data.can.withdraw', true);

        $wf = WorkflowInstance::where('process', 'manual-journal')->value('id');
        $this->actingAs($this->accountant)->postJson("/api/v1/workflow/requests/{$wf}/approve")->assertUnprocessable();
        $this->actingAs($this->admin)->postJson("/api/v1/workflow/requests/{$wf}/approve")->assertOk();
        $this->actingAs($this->accountant)->getJson("/api/v1/accounting/journals/{$id}")->assertJsonPath('data.status', 'posted')->assertJsonPath('data.reference_no', 'JV-2026-00001')
            ->assertJsonPath('data.period', 'Nov 2026');

        $sale = $this->postJson('/api/v1/accounting/journals', ['date' => '2026-11-12', 'description' => 'Supplies bought', 'lines' => [
            ['account_id' => $ids['5100'], 'debit' => 250000, 'department_id' => $this->pharmacy->id, 'fund_source_id' => $fund], ['account_id' => $ids['1100'], 'credit' => 250000],
        ]])->json('data.id');
        $this->postJson("/api/v1/accounting/journals/{$sale}/submit")->assertOk();
        $this->actingAs($this->admin)->postJson('/api/v1/workflow/requests/'.WorkflowInstance::where('process', 'manual-journal')->latest('id')->value('id').'/approve')->assertOk();

        $this->actingAs($this->accountant)->getJson("/api/v1/accounting/reports/ledger?account_id={$ids['1100']}&from=2026-11-01&to=2026-11-30")->assertOk()
            ->assertJsonPath('opening', 0)->assertJsonPath('closing', 750000)->assertJsonCount(2, 'data')->assertJsonPath('data.1.balance', 750000);
        $this->getJson('/api/v1/accounting/reports/trial-balance?from=2026-01-01&to=2026-12-31')->assertJsonPath('balanced', true)
            ->assertJsonPath('totals.closing_debit', 1000000)->assertJsonCount(3, 'data');
        $this->getJson("/api/v1/accounting/reports/trial-balance?from=2026-01-01&to=2026-12-31&department_id={$this->pharmacy->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', '5100');
        $this->getJson('/api/v1/accounting/reports/summary')->assertJsonPath('data.expense', 250000)->assertJsonPath('data.surplus', -250000);

        $this->postJson("/api/v1/accounting/journals/{$sale}/reverse", ['date' => '2026-11-15', 'reason' => 'Wrong supplier'])->assertOk()->assertJsonPath('data.reference_no', 'JV-2026-00003');
        $this->getJson("/api/v1/accounting/journals/{$sale}")->assertJsonPath('data.status', 'reversed')->assertJsonPath('data.reversed_by.reference_no', 'JV-2026-00003');
        $this->getJson("/api/v1/accounting/reports/ledger?account_id={$ids['1100']}&from=2026-11-01&to=2026-11-30")->assertJsonPath('closing', 1000000);
        $this->postJson("/api/v1/accounting/journals/{$sale}/reverse", ['date' => '2026-11-15', 'reason' => 'Again'])->assertUnprocessable();

        $periods = $this->getJson('/api/v1/accounting/fiscal-years')->json('data.0.periods');
        $this->postJson("/api/v1/accounting/periods/{$periods[10]['id']}/close")->assertUnprocessable()->assertJsonValidationErrors(['period' => 'earlier periods']);
        foreach (array_slice($periods, 0, 11) as $p) {
            $this->postJson("/api/v1/accounting/periods/{$p['id']}/close")->assertOk();
        }
        $late = $this->postJson('/api/v1/accounting/journals', ['date' => '2026-11-20', 'description' => 'Late', 'lines' => [
            ['account_id' => $ids['5100'], 'debit' => 10], ['account_id' => $ids['1100'], 'credit' => 10],
        ]])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/accounting/journals/{$late}/submit")->assertUnprocessable()->assertJsonValidationErrors(['date' => 'closed']);
        $this->postJson("/api/v1/accounting/periods/{$periods[0]['id']}/reopen")->assertUnprocessable();
        $this->postJson("/api/v1/accounting/periods/{$periods[10]['id']}/reopen")->assertOk()->assertJsonPath('data.status', 'open');
        $this->deleteJson("/api/v1/accounting/accounts/{$ids['5100']}")->assertUnprocessable();
    }
}
