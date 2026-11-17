<?php

namespace Modules\Accounting\Tests\Feature;

use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Accounting\Services\PostingService;
use Modules\Payments\Models\PaymentRequest;
use Modules\Payments\Models\PaymentSetting;
use Modules\Receivables\Models\Invoice;
use Modules\Receivables\Models\ReceivableSetting;
use Modules\Reporting\Support\FinanceReports;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class OpeningAndYearEndTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_go_live_balances_then_a_year_closed_and_reopened(): void
    {
        $this->travelTo('2026-03-01 09:00:00');
        [$company] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'accounting'], ['slug' => 'payments'], ['slug' => 'receivables'], ['slug' => 'procurement']]]);
        setPermissionsTeamId($company->id);
        [$user, $a] = app(CompanyContext::class)->run($company, function () use ($company) {
            Role::findOrCreate('acc', 'web')->syncPermissions(['accounting.view', 'accounting.journals', 'accounting.manage', 'accounting.opening', 'reports.view']);
            $u = tap(User::factory()->create(['company_id' => $company->id]))->assignRole('acc');
            app(FiscalCalendar::class)->createYear('2026-01-01');
            $mk = fn ($code, $name, $type) => Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'is_postable' => true, 'is_active' => true])->id;
            $a = ['bank' => $mk('1100', 'Bank', 'asset'), 'debtors' => $mk('1200', 'Receivables', 'asset'), 'payable' => $mk('2000', 'Payments due', 'liability'),
                'creditors' => $mk('2100', 'Trade creditors', 'liability'), 'retained' => $mk('3100', 'Retained earnings', 'equity'), 'revenue' => $mk('4000', 'Fees', 'revenue'),
                'expense' => $mk('5000', 'Supplies', 'expense')];
            ReceivableSetting::current()->fill(['receivable_account_id' => $a['debtors']])->save();
            PaymentSetting::current()->fill(['payable_account_id' => $a['payable']])->save();
            $this->assertSame(2, WorkflowTemplate::whereIn('process', ['opening-balance', 'year-end-close'])->count());
            WorkflowTemplate::whereIn('process', ['opening-balance', 'year-end-close'])->delete();

            return [$u, $a];
        });
        $this->actingAs($user);

        $this->postJson('/api/v1/accounting/opening-balances', ['kind' => 'creditors', 'date' => '2026-01-01'])->assertCreated();
        $creditors = (int) $this->getJson('/api/v1/accounting/opening-balances')->json('data.0.id');
        $this->postJson("/api/v1/accounting/opening-balances/{$creditors}/rows", ['rows' => [['reference' => 'BILL-1', 'supplier' => 'Office World Ltd', 'account_code' => '2100', 'amount' => 150000]]])
            ->assertJsonPath('data.added', 1);
        $this->postJson("/api/v1/accounting/opening-balances/{$creditors}/submit")->assertUnprocessable();

        $this->postJson('/api/v1/accounting/opening-balances', ['kind' => 'accounts', 'date' => '2027-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $tb = $this->postJson('/api/v1/accounting/opening-balances', ['kind' => 'accounts', 'date' => '2026-01-01'])->json('data.id');
        $res = $this->postJson("/api/v1/accounting/opening-balances/{$tb}/rows", ['rows' => [
            ['reference' => 'TB-1', 'account_code' => '1100', 'debit' => 1000000],
            ['reference' => 'TB-2', 'account_code' => '1200', 'debit' => 300000],
            ['reference' => 'TB-3', 'account_code' => '2100', 'credit' => 200000],
            ['reference' => 'TB-4', 'account_code' => '9999', 'credit' => 5],
            ['reference' => 'TB-1', 'account_code' => '1100', 'debit' => 5],
            ['reference' => 'TB-5', 'account_code' => '3100', 'credit' => 900000],
        ]])->assertOk();
        $this->assertSame(4, $res->json('data.added'));
        $this->assertSame([false, false], [$res->json('data.results.3.ok'), $res->json('data.results.4.ok')]);
        $this->assertStringContainsString('9999', $res->json('data.results.3.message'));
        $this->postJson("/api/v1/accounting/opening-balances/{$tb}/submit")->assertUnprocessable();
        $this->postJson("/api/v1/accounting/opening-balances/{$tb}/rows", ['rows' => [['reference' => 'TB-6', 'account_code' => '3100', 'credit' => 200000]]])->assertJsonPath('data.added', 1);
        $this->postJson("/api/v1/accounting/opening-balances/{$tb}/submit")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->getJson("/api/v1/accounting/opening-balances/{$tb}")->assertJsonPath('data.journal.reference_no', 'JV-2026-00001')->assertJsonPath('data.can.edit', false);

        $more = $this->postJson("/api/v1/accounting/opening-balances/{$creditors}/rows", ['rows' => [['reference' => 'BILL-2', 'supplier' => 'Office World Ltd', 'account_code' => '2100', 'amount' => 100000]]]);
        $this->postJson("/api/v1/accounting/opening-balances/{$creditors}/submit")->assertUnprocessable();
        $line = collect($this->getJson("/api/v1/accounting/opening-balances/{$creditors}")->json('data.lines'))->firstWhere('reference', 'BILL-2')['id'];
        $this->deleteJson("/api/v1/accounting/opening-balances/{$creditors}/rows/{$line}")->assertNoContent();
        $this->postJson("/api/v1/accounting/opening-balances/{$creditors}/submit")->assertOk()->assertJsonPath('data.status', 'approved');

        $debtors = $this->postJson('/api/v1/accounting/opening-balances', ['kind' => 'debtors', 'date' => '2026-01-01'])->json('data.id');
        $this->postJson("/api/v1/accounting/opening-balances/{$debtors}/rows", ['rows' => [['reference' => 'INV-9', 'customer' => 'Jubilee Insurance', 'amount' => 300000, 'due_date' => '2026-01-31']]])
            ->assertJsonPath('data.added', 1);
        $this->postJson("/api/v1/accounting/opening-balances/{$debtors}/submit")->assertOk();

        app(CompanyContext::class)->run($company, function () use ($a) {
            $bill = PaymentRequest::where('title', 'like', 'Opening balance BILL-1%')->firstOrFail();
            $this->assertSame(['approved', null, 150000.0], [$bill->status, $bill->budget_id, $bill->total]);
            $this->assertSame($a['creditors'], $bill->lines()->first()->account_id);
            $inv = Invoice::where('reference', 'INV-9')->firstOrFail();
            $this->assertSame(['open', null, 300000.0], [$inv->status, $inv->journal_entry_id, $inv->total]);
            $this->assertSame(1, JournalEntry::count());
        });
        $rec = collect($this->getJson('/api/v1/accounting/opening-balances/reconciliation')->json('data'))->keyBy('kind');
        $this->assertEquals(50000, $rec['creditors']['difference']);
        $this->assertEquals(0, $rec['debtors']['difference']);

        app(CompanyContext::class)->run($company, function () use ($a, $user) {
            app(PostingService::class)->post('2026-06-01', 'Fees', [['account_id' => $a['bank'], 'debit' => 500000], ['account_id' => $a['revenue'], 'credit' => 500000]], $user);
            app(PostingService::class)->post('2026-06-02', 'Supplies', [['account_id' => $a['expense'], 'debit' => 200000], ['account_id' => $a['bank'], 'credit' => 200000]], $user);
        });
        $this->assertReports($company, $user, $a);
        $this->travelTo('2027-01-10 09:00:00');

        $year = $this->getJson('/api/v1/accounting/year-end')->assertOk()->json('next');
        $this->assertFalse($year['preview']['can_close']);
        $this->assertEquals(300000, $year['preview']['result']);
        $this->postJson("/api/v1/accounting/year-end/{$year['id']}/close")->assertUnprocessable();
        $this->putJson('/api/v1/accounting/year-end/settings', ['retained_earnings_account_id' => $a['revenue']])->assertUnprocessable();
        $this->putJson('/api/v1/accounting/year-end/settings', ['retained_earnings_account_id' => $a['retained']])->assertOk();
        $this->postJson("/api/v1/accounting/year-end/{$year['id']}/close")->assertOk()->assertJsonPath('data.status', 'closed');

        $years = collect($this->getJson('/api/v1/accounting/year-end')->json('data'))->keyBy('name');
        $this->assertSame('closed', $years['2026']['status']);
        $this->assertSame('open', $years['2027']['status']);
        $this->assertCount(3, $years['2026']['statements']);
        $snap = $this->getJson("/api/v1/accounting/year-end/{$year['id']}/statements/income-statement")->assertOk()->json('data');
        $this->assertEquals(300000, collect($snap['rows'])->last()['amount']);
        $position = collect($this->getJson("/api/v1/accounting/year-end/{$year['id']}/statements/financial-position")->json('data.rows'))->keyBy('code');
        $this->assertEquals(1400000, $position['3100']['amount']);

        app(CompanyContext::class)->run($company, function () use ($a, $user) {
            $reports = app(FinanceReports::class);
            $this->assertEquals(300000, collect($reports->build('income-statement', ['from' => '2026-01-01', 'to' => '2026-12-31'])['rows'])->last()['amount']);
            $this->assertEquals(0, collect($reports->build('income-statement', ['from' => '2027-01-01', 'to' => '2027-12-31'])['rows'])->last()['amount']);
            $this->expectsNoPostingIn2026($user, $a);
        });
        $this->postJson('/api/v1/accounting/opening-balances', ['kind' => 'accounts', 'date' => '2026-01-01'])->assertUnprocessable();
        $tb2027 = collect($this->getJson('/api/v1/accounting/reports/trial-balance?from=2027-01-01&to=2027-01-31')->json('data'))->keyBy('code');
        $this->assertEquals(1300000, $tb2027['1100']['opening']);
        $this->assertEquals(1400000, $tb2027['3100']['opening']);
        $this->assertArrayNotHasKey('4000', $tb2027->all());

        $this->postJson("/api/v1/accounting/year-end/{$year['id']}/reopen", ['reason' => 'Audit adjustment'])->assertOk()->assertJsonPath('data.status', 'open');
        $this->getJson("/api/v1/accounting/year-end/{$year['id']}/statements/income-statement")->assertNotFound();
        app(CompanyContext::class)->run($company, function () {
            $reports = app(FinanceReports::class);
            $this->assertEquals(300000, collect($reports->build('income-statement', ['from' => '2026-01-01', 'to' => '2026-12-31'])['rows'])->last()['amount']);
            $position = collect($reports->build('financial-position', ['date' => '2026-12-31'])['rows'])->keyBy('code');
            $this->assertEquals(1100000, $position['3100']['amount']);
            $this->assertSame('open', FiscalYear::where('name', '2026')->value('status'));
        });
    }

    private function assertReports($company, User $user, array $a): void
    {
        app(CompanyContext::class)->run($company, function () use ($user, $a) {
            $bank = \Modules\Accounting\Models\CashBankAccount::create(['name' => 'CRDB', 'kind' => 'bank', 'account_id' => $a['bank'], 'is_active' => true]);
            Account::find($a['creditors'])->update(['cash_flow' => 'operating']);
            \Modules\Receivables\Models\Receipt::create(['number' => 'RCT-1', 'kind' => 'direct', 'payer_name' => 'Walk in', 'date' => '2026-06-03', 'cash_bank_account_id' => $bank->id,
                'method' => 'mobile', 'amount' => 25000, 'status' => 'posted', 'created_by' => $user->id]);
            $r = app(FinanceReports::class);
            $rows = fn ($key, $in) => collect($r->build($key, $in)['rows']);

            $cash = $rows('cash-flow', ['from' => '2026-02-01', 'to' => '2026-12-31']);
            $this->assertEquals(1000000, $cash->first()['amount']);
            $this->assertEquals(300000, $cash->firstWhere('section', 'Net cash from Operating activities')['amount']);
            $this->assertEquals(1300000, $cash->last()['amount']);

            $equity = $rows('equity-changes', ['from' => '2026-02-01', 'to' => '2026-12-31']);
            $this->assertEquals(1100000, $equity->first()['opening']);
            $this->assertEquals(1400000, $equity->last()['closing']);

            $monthly = $r->build('income-monthly', ['from' => '2026-06-01', 'to' => '2026-06-30', 'department_id' => null]);
            $this->assertSame('Jun 26', collect($monthly['columns'])->firstWhere('key', 'm202606')['label']);
            $this->assertEquals(300000, collect($monthly['rows'])->last()['total']);

            $this->assertCount(0, $rows('ledger-check', ['from' => '2026-01-01', 'to' => '2026-12-31']));
            $this->assertEquals(150000, $rows('payables-ageing', [])->last()['total']);
            $this->assertEquals(150000, $rows('supplier-list', [])->firstWhere('supplier', 'Office World Ltd')['balance']);
            $this->assertEquals(150000, $rows('unpaid-requests', [])->last()['amount']);
            $this->assertCount(1, $rows('unpaid-vouchers', []));
            $supplier = \Modules\Procurement\Models\Supplier::where('name', 'Office World Ltd')->value('id');
            $this->assertEquals(150000, $rows('supplier-statement', ['supplier_id' => $supplier, 'from' => '2025-01-01', 'to' => '2027-12-31'])->last()['balance']);
            $collections = $rows('collections-summary', ['from' => '2026-01-01', 'to' => '2026-12-31', 'group' => 'method']);
            $this->assertSame('Mobile money', $collections->first()['group']);
            $this->assertEquals(25000, $collections->last()['amount']);
        });
    }

    private function expectsNoPostingIn2026(User $user, array $a): void
    {
        try {
            app(PostingService::class)->post('2026-12-30', 'Late', [['account_id' => $a['bank'], 'debit' => 1], ['account_id' => $a['revenue'], 'credit' => 1]], $user);
            $this->fail('Posting into a closed year should be refused.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertTrue(true);
        }
    }
}
