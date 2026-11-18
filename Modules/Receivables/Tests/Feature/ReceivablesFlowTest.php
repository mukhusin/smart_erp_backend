<?php

namespace Modules\Receivables\Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Services\BudgetService;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class ReceivablesFlowTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private User $billing;

    private User $director;

    private array $acc = [];

    private int $bank;

    private int $item;

    private int $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-18 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'accounting'], ['slug' => 'budgeting'], ['slug' => 'receivables']]]);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->run($this->company, function () {
            Role::findOrCreate('billing', 'web')->syncPermissions(['accounting.view', 'budgeting.view', 'receivables.view', 'receivables.bill', 'receivables.receipts', 'receivables.manage']);
            $this->billing = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('billing');
            $this->director = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('director-general');
            $this->dept = Department::create(['name' => 'Outpatient', 'is_active' => true])->id;
            $year = app(FiscalCalendar::class)->createYear('2026-01-01');
            foreach ([['1100', 'CRDB', 'asset'], ['1200', 'Receivables', 'asset'], ['3000', 'Fund balance', 'equity'], ['4100', 'Patient services', 'revenue'], ['4200', 'Donations', 'revenue']] as [$code, $name, $type]) {
                $this->acc[$code] = Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'is_postable' => true, 'is_budgetable' => $type === 'revenue', 'is_active' => true])->id;
            }
            $this->bank = CashBankAccount::create(['name' => 'CRDB main', 'kind' => 'bank', 'account_id' => $this->acc['1100'], 'currency' => 'TZS', 'is_active' => true])->id;
            $budget = Budget::create(['fiscal_year_id' => $year->id, 'title' => 'Budget 2026', 'phasing' => 'quarter', 'status' => 'approved', 'created_by' => $this->billing->id]);
            $this->item = BudgetItem::create(['budget_id' => $budget->id, 'department_id' => $this->dept, 'account_id' => $this->acc['4100'], 'description' => 'Insurance claims',
                'quantity' => 1, 'unit_cost' => 2000000, 'original_amount' => 2000000, 'revised_amount' => 2000000])->id;
            app(BudgetService::class)->record($this->item, 'approved', 2000000, $budget, 'Approved');
            $director = Role::where('name', 'director-general')->value('id');
            foreach (['credit-note', 'receipt-void'] as $process) {
                WorkflowTemplate::where('process', $process)->delete();
                WorkflowTemplate::create(['process' => $process, 'company_branch_id' => null, 'is_active' => true])
                    ->steps()->create(['step_order' => 1, 'name' => 'Director', 'approver_type' => 'role', 'role_id' => $director, 'allow_send_back' => false]);
            }
            WorkflowTemplate::where('process', 'customer-invoice')->delete();
        });
    }

    private function approve(string $process): void
    {
        $id = WorkflowInstance::where('process', $process)->where('status', 'pending')->latest('id')->value('id');
        $this->actingAs($this->director)->postJson("/api/v1/workflow/requests/{$id}/approve")->assertOk();
        $this->actingAs($this->billing);
    }

    public function test_invoice_receipts_credit_note_void_and_reports(): void
    {
        $this->actingAs($this->billing)->getJson('/api/v1/receivables/lookups')->assertOk()->assertJsonPath('data.budget_lines.0.id', $this->item);
        $customer = $this->postJson('/api/v1/receivables/customers', ['name' => 'NHIF', 'kind' => 'insurer', 'terms_days' => 45])->assertCreated()->assertJsonPath('data.code', 'CUS-0001')->json('data.id');

        $body = ['customer_id' => $customer, 'date' => '2026-10-01', 'reference' => 'Claim Oct', 'lines' => [
            ['description' => 'Consultations', 'account_id' => $this->acc['4100'], 'department_id' => $this->dept, 'budget_item_id' => $this->item, 'quantity' => 20, 'unit_price' => 10000],
            ['description' => 'Lab tests', 'account_id' => $this->acc['4100'], 'quantity' => 4, 'unit_price' => 25000],
        ]];
        $this->postJson('/api/v1/receivables/invoices', ['lines' => [['description' => 'x', 'account_id' => $this->acc['1100'], 'unit_price' => 5]]] + $body)->assertUnprocessable()->assertJsonValidationErrors(['lines.0.account_id']);
        $inv = $this->postJson('/api/v1/receivables/invoices', $body)->assertCreated()->assertJsonPath('data.number', 'INV-2026-00001')->json('data.id');
        $this->getJson("/api/v1/receivables/invoices/{$inv}")->assertJsonPath('data.total', 300000)->assertJsonPath('data.due_date', '2026-11-15');
        $this->postJson("/api/v1/receivables/invoices/{$inv}/issue")->assertUnprocessable()->assertJsonValidationErrors(['settings']);
        $this->putJson('/api/v1/receivables/settings', ['receivable_account_id' => $this->acc['1200'], 'default_terms_days' => 30])->assertOk();
        $this->postJson("/api/v1/receivables/invoices/{$inv}/issue")->assertOk()->assertJsonPath('data.status', 'open');
        $this->getJson("/api/v1/receivables/invoices/{$inv}")->assertJsonPath('data.overdue', true)->assertJsonPath('data.can.receive', true);

        $receipt = ['kind' => 'invoice', 'customer_id' => $customer, 'date' => '2026-11-18', 'cash_bank_account_id' => $this->bank, 'method' => 'bank', 'reference' => 'TT-1'];
        $this->postJson('/api/v1/receivables/receipts', $receipt + ['allocations' => [['invoice_id' => $inv, 'amount' => 350000]]])->assertUnprocessable()->assertJsonValidationErrors(['allocations.0.amount']);
        $r1 = $this->postJson('/api/v1/receivables/receipts', $receipt + ['allocations' => [['invoice_id' => $inv, 'amount' => 100000]]])->assertCreated()->assertJsonPath('data.number', 'RCT-2026-00001')->json('data.id');
        $this->getJson("/api/v1/receivables/invoices/{$inv}")->assertJsonPath('data.status', 'partial')->assertJsonPath('data.balance', 200000);

        $this->postJson("/api/v1/receivables/invoices/{$inv}/credit-notes", ['date' => '2026-11-18', 'amount' => 250000, 'reason' => 'x'])->assertUnprocessable();
        $this->postJson("/api/v1/receivables/invoices/{$inv}/credit-notes", ['date' => '2026-11-18', 'amount' => 50000, 'reason' => 'Rejected claim lines'])->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->approve('credit-note');
        $this->getJson("/api/v1/receivables/invoices/{$inv}")->assertJsonPath('data.credited', 50000)->assertJsonPath('data.balance', 150000)->assertJsonPath('data.credit_notes.0.status', 'approved');

        $this->postJson('/api/v1/receivables/receipts', $receipt + ['allocations' => [['invoice_id' => $inv, 'amount' => 150000]]])->assertCreated();
        $this->getJson("/api/v1/receivables/invoices/{$inv}")->assertJsonPath('data.status', 'paid')->assertJsonPath('data.balance', 0);

        $this->postJson('/api/v1/receivables/receipts', ['kind' => 'direct', 'payer_name' => 'Rotary Club', 'date' => '2026-11-18', 'cash_bank_account_id' => $this->bank, 'method' => 'cash',
            'lines' => [['description' => 'Donation for children ward', 'account_id' => $this->acc['4200'], 'amount' => 80000]]])->assertCreated();

        $this->postJson("/api/v1/receivables/receipts/{$r1}/void", ['reason' => 'Bounced transfer'])->assertOk()->assertJsonPath('data.status', 'void_pending');
        $this->approve('receipt-void');
        $this->getJson("/api/v1/receivables/receipts/{$r1}")->assertJsonPath('data.status', 'voided');
        $this->getJson("/api/v1/receivables/invoices/{$inv}")->assertJsonPath('data.status', 'partial')->assertJsonPath('data.balance', 100000)->assertJsonPath('data.can.void', false);
        $this->postJson("/api/v1/receivables/invoices/{$inv}/void", ['reason' => 'x'])->assertUnprocessable();

        $this->getJson("/api/v1/receivables/customers/{$customer}")->assertJsonPath('ageing.d30', 100000)->assertJsonCount(3, 'statement')->assertJsonPath('statement.2.balance', 100000);
        $this->getJson('/api/v1/receivables/customers?search=NHIF')->assertJsonPath('data.0.balance', 100000)->assertJsonPath('data.0.overdue', 100000);
        $this->getJson('/api/v1/receivables/ageing')->assertJsonPath('totals.total', 100000)->assertJsonPath('data.0.name', 'NHIF');
        $this->getJson('/api/v1/receivables/dashboard')->assertOk()->assertJsonPath('totals.outstanding', 100000)->assertJsonPath('totals.direct_month', 80000)
            ->assertJsonPath('top_debtors.0.name', 'NHIF')->assertJsonCount(12, 'months');
        $this->getJson("/api/v1/budgeting/items/{$this->item}")->assertJsonPath('data.actual', 166666.67);
        $this->artisan('budget:check-balances')->assertSuccessful();
    }

    private function invoice(int $customer, int $price, string $date = '2026-11-10', array $extra = []): int
    {
        $id = $this->postJson('/api/v1/receivables/invoices', $extra + ['customer_id' => $customer, 'date' => $date, 'lines' => [
            ['description' => 'Consultation', 'account_id' => $this->acc['4100'], 'quantity' => 1, 'unit_price' => $price]]])->assertCreated()->json('data.id');

        return $id;
    }

    public function test_credit_limit_check_and_insurance_claim_lifecycle(): void
    {
        $this->actingAs($this->billing)->putJson('/api/v1/receivables/settings', ['receivable_account_id' => $this->acc['1200'], 'default_terms_days' => 30, 'credit_check' => 'block'])->assertOk()
            ->assertJsonPath('data.credit_check', 'block')->assertJsonPath('data.claims_enabled', false);
        $insurer = $this->postJson('/api/v1/receivables/customers', ['name' => 'Afya Insurance', 'kind' => 'insurer', 'credit_limit' => 500000])->assertCreated()->json('data.id');
        $patient = $this->postJson('/api/v1/receivables/customers', ['name' => 'Juma Ali', 'kind' => 'patient'])->assertCreated()->json('data.id');

        $a = $this->invoice($insurer, 300000, '2026-11-10', ['beneficiary' => 'Juma Ali', 'member_no' => 'AF-77']);
        $this->postJson("/api/v1/receivables/invoices/{$a}/issue")->assertOk();
        $b = $this->invoice($insurer, 250000, '2026-11-12');
        $this->postJson("/api/v1/receivables/invoices/{$b}/issue")->assertUnprocessable()->assertJsonValidationErrors(['invoice']);
        $this->putJson('/api/v1/receivables/settings', ['default_terms_days' => 30, 'receivable_account_id' => $this->acc['1200'], 'credit_check' => 'warn'])->assertOk();
        $this->postJson("/api/v1/receivables/invoices/{$b}/issue")->assertOk();
        $this->getJson("/api/v1/receivables/invoices/{$b}")->assertJsonPath('data.over_limit', true);
        $this->getJson("/api/v1/receivables/customers/{$insurer}")->assertJsonPath('data.on_hold', false)->assertJsonPath('credit.exposure', 550000)->assertJsonPath('credit.available', -50000);
        $this->putJson("/api/v1/receivables/customers/{$patient}", ['name' => 'Juma Ali', 'kind' => 'patient', 'on_hold' => true])->assertOk();
        $this->putJson('/api/v1/receivables/settings', ['default_terms_days' => 30, 'receivable_account_id' => $this->acc['1200'], 'credit_check' => 'block'])->assertOk();
        $p = $this->invoice($patient, 1000);
        $this->postJson("/api/v1/receivables/invoices/{$p}/issue")->assertUnprocessable();
        $this->putJson("/api/v1/receivables/customers/{$patient}", ['name' => 'Juma Ali', 'kind' => 'patient', 'on_hold' => false])->assertOk();
        $this->putJson('/api/v1/receivables/settings', ['default_terms_days' => 30, 'receivable_account_id' => $this->acc['1200'], 'credit_check' => 'off'])->assertOk();

        $this->postJson('/api/v1/receivables/claims', ['customer_id' => $insurer, 'period_from' => '2026-11-01', 'period_to' => '2026-11-30'])->assertUnprocessable()->assertJsonValidationErrors(['claim']);
        $this->putJson('/api/v1/receivables/settings', ['default_terms_days' => 30, 'receivable_account_id' => $this->acc['1200'], 'claims_enabled' => true])->assertOk();
        $this->getJson('/api/v1/receivables/lookups')->assertJsonPath('data.claims_enabled', true);
        $this->getJson('/api/v1/me')->assertJsonPath('data.features', ['receivables.claims']);
        $claim = $this->postJson('/api/v1/receivables/claims', ['customer_id' => $insurer, 'period_from' => '2026-11-01', 'period_to' => '2026-11-30'])->assertCreated()
            ->assertJsonPath('data.number', 'CLM-2026-00001')->json('data.id');
        $c = $this->getJson("/api/v1/receivables/claims/{$claim}")->assertOk()->assertJsonCount(2, 'data.lines')->assertJsonPath('data.claimed', 550000)->json('data');
        $this->postJson('/api/v1/receivables/claims', ['customer_id' => $insurer, 'period_from' => '2026-11-01', 'period_to' => '2026-11-30'])->assertCreated();
        $this->getJson('/api/v1/receivables/claims?status=draft')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.lines', 0);

        $this->putJson("/api/v1/receivables/claims/{$claim}", ['invoice_ids' => [$a]])->assertOk();
        $this->putJson("/api/v1/receivables/claims/{$claim}", ['invoice_ids' => [$a, $b]])->assertOk();
        $this->postJson("/api/v1/receivables/claims/{$claim}/submit", ['submitted_on' => '2026-11-18', 'payer_reference' => 'AF/B/11'])->assertOk()->assertJsonPath('data.status', 'submitted');
        $lines = $this->getJson("/api/v1/receivables/claims/{$claim}")->json('data.lines');
        $lineA = collect($lines)->firstWhere('invoice_id', $a)['id'];
        $lineB = collect($lines)->firstWhere('invoice_id', $b)['id'];
        $this->postJson("/api/v1/receivables/claims/{$claim}/respond", ['responded_on' => '2026-11-18', 'lines' => [['line_id' => $lineA, 'approved' => 200000], ['line_id' => $lineB, 'approved' => 250000]]])
            ->assertUnprocessable();
        $this->postJson("/api/v1/receivables/claims/{$claim}/respond", ['responded_on' => '2026-11-18', 'lines' => [['line_id' => $lineA, 'approved' => 200000, 'reason' => 'Drug not covered'],
            ['line_id' => $lineB, 'approved' => 150000, 'reason' => 'Above tariff']]])->assertOk()->assertJsonPath('data.status', 'responded');
        $this->getJson("/api/v1/receivables/claims/{$claim}")->assertJsonPath('data.approved', 350000)->assertJsonPath('data.rejected', 200000)->assertJsonPath('data.outstanding', 350000);

        $this->postJson("/api/v1/receivables/claims/{$claim}/lines/{$lineA}/resolve", ['action' => 'rebilled', 'date' => '2026-11-18', 'customer_id' => $patient])->assertOk();
        $this->approve('credit-note');
        $l = collect($this->getJson("/api/v1/receivables/claims/{$claim}")->json('data.lines'))->firstWhere('id', $lineA);
        $this->assertSame('rebilled', $l['resolution']);
        $this->assertEquals(200000, $l['invoice_balance']);
        $this->getJson("/api/v1/receivables/invoices/{$l['rebill_invoice_id']}")->assertJsonPath('data.total', 100000)->assertJsonPath('data.customer.id', $patient)
            ->assertJsonPath('data.beneficiary', 'Juma Ali')->assertJsonPath('data.status', 'draft');
        $this->postJson("/api/v1/receivables/claims/{$claim}/lines/{$lineB}/resolve", ['action' => 'written_off', 'date' => '2026-11-18'])->assertOk();
        $this->approve('credit-note');
        $this->postJson("/api/v1/receivables/claims/{$claim}/lines/{$lineB}/resolve", ['action' => 'written_off', 'date' => '2026-11-18'])->assertUnprocessable();
        $this->getJson("/api/v1/receivables/invoices/{$a}")->assertJsonPath('data.claim.id', $claim);

        $this->postJson('/api/v1/receivables/receipts', ['kind' => 'invoice', 'customer_id' => $insurer, 'date' => '2026-11-18', 'cash_bank_account_id' => $this->bank, 'method' => 'bank',
            'allocations' => [['invoice_id' => $a, 'amount' => 200000], ['invoice_id' => $b, 'amount' => 150000]]])->assertCreated();
        $this->getJson("/api/v1/receivables/claims/{$claim}")->assertJsonPath('data.status', 'closed')->assertJsonPath('data.outstanding', 0);
    }

    public function test_output_vat_on_invoices_credit_notes_and_cash_sales(): void
    {
        $out = app(CompanyContext::class)->run($this->company, fn () => Account::create(['code' => '2300', 'name' => 'Output VAT', 'type' => 'liability', 'is_postable' => true, 'is_active' => true])->id);
        $in = app(CompanyContext::class)->run($this->company, fn () => Account::create(['code' => '1300', 'name' => 'Input VAT', 'type' => 'asset', 'is_postable' => true, 'is_active' => true])->id);
        $this->actingAs($this->billing)->putJson('/api/v1/receivables/settings', ['receivable_account_id' => $this->acc['1200'], 'default_terms_days' => 30])->assertOk();
        $customer = $this->postJson('/api/v1/receivables/customers', ['name' => 'Acme Ltd', 'kind' => 'company'])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/receivables/lookups')->assertJsonPath('data.vat_registered', false)->assertJsonCount(0, 'data.tax_codes');
        $plain = $this->invoice($customer, 100000);
        $this->getJson("/api/v1/receivables/invoices/{$plain}")->assertJsonPath('data.tax_total', 0);
        $this->postJson("/api/v1/receivables/invoices/{$plain}/issue")->assertOk();

        $this->actingAs($this->admin)->putJson('/api/v1/accounting/vat', ['vat_registered' => true, 'output_account_id' => $out, 'input_account_id' => $in])->assertOk();
        $std = collect($this->getJson('/api/v1/receivables/lookups')->assertJsonPath('data.vat_registered', true)->json('data.tax_codes'))->firstWhere('code', 'S')['id'];
        $inv = $this->actingAs($this->billing)->postJson('/api/v1/receivables/invoices', ['customer_id' => $customer, 'date' => '2026-11-10', 'lines' => [
            ['description' => 'Lab tests', 'account_id' => $this->acc['4100'], 'quantity' => 2, 'unit_price' => 50000, 'tax_code_id' => $std]]])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/receivables/invoices/{$inv}")->assertJsonPath('data.total', 118000)->assertJsonPath('data.tax_total', 18000)->assertJsonPath('data.lines.0.tax_amount', 18000);
        $this->postJson("/api/v1/receivables/invoices/{$inv}/issue")->assertOk();
        $this->postJson("/api/v1/receivables/invoices/{$inv}/credit-notes", ['date' => '2026-11-18', 'amount' => 59000, 'reason' => 'Half not done'])->assertCreated();
        $this->approve('credit-note');
        $this->postJson('/api/v1/receivables/receipts', ['kind' => 'direct', 'payer_name' => 'Walk-in', 'date' => '2026-11-18', 'cash_bank_account_id' => $this->bank, 'method' => 'cash',
            'lines' => [['description' => 'Pharmacy sale', 'account_id' => $this->acc['4100'], 'amount' => 11800, 'tax_code_id' => $std]]])->assertCreated();

        $sum = $this->actingAs($this->admin)->getJson('/api/v1/accounting/vat/summary?from=2026-11-01&to=2026-11-30')->assertOk()->json('data');
        $this->assertEquals(18000, collect($sum['sales'])->firstWhere('code', 'S')['vat']);
        $this->assertEquals(9000, $sum['credit_notes']['vat']);
        $this->assertEquals(1800, $sum['cash_sales'][0]['vat']);
        $this->assertEquals(10800, $sum['output_vat']);
        $this->assertEquals(10800, $sum['ledger']['output']);
        $this->assertEquals(100000, collect($sum['sales'])->firstWhere('code', '—')['net']);
    }
}
