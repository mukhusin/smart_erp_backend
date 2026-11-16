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
}
