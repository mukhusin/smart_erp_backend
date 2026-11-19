<?php

namespace Modules\Procurement\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Accounting\Services\PostingService;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Services\BudgetService;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\ItemCategory;
use Modules\Inventory\Models\Store;
use Modules\Inventory\Models\Unit;
use Modules\Procurement\Actions\ManageSuppliers;
use Modules\Procurement\Models\Supplier;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class PurchaseFlowTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private array $u = [];

    private array $acc = [];

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-18 09:00:00');
        [$this->company, $admin] = $this->createCompany('Alpha', ['modules' => array_map(fn ($s) => ['slug' => $s], ['hr', 'admin', 'accounting', 'budgeting', 'payments', 'procurement', 'inventory'])]);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->run($this->company, function () {
            Role::findOrCreate('buyer', 'web')->syncPermissions(['procurement.view', 'procurement.orders']);
            Role::findOrCreate('keeper', 'web')->syncPermissions(['procurement.receive']);
            Role::findOrCreate('acc', 'web')->syncPermissions(['accounting.view', 'budgeting.view', 'payments.view', 'payments.vouchers', 'payments.pay', 'payments.manage', 'procurement.manage']);
            foreach (['buyer', 'keeper', 'acc', 'director-general'] as $r) {
                $this->u[$r] = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole($r);
            }
            $dept = Department::create(['name' => 'Administration', 'is_active' => true]);
            $this->u['head'] = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
            Employee::create(['employee_no' => 'H1', 'fname' => 'Head', 'lname' => 'Admin', 'gender' => 'Female', 'department_id' => $dept->id, 'user_id' => $this->u['head']->id,
                'employment_status' => 'confirmed', 'date_of_birth' => '1985-01-01']);
            $year = app(FiscalCalendar::class)->createYear('2026-01-01');
            foreach ([['1100', 'Bank', 'asset'], ['1400', 'Stock', 'asset'], ['2100', 'Payables', 'liability'], ['2150', 'Goods received not billed', 'liability'], ['3000', 'Fund', 'equity'], ['5600', 'Office supplies', 'expense']] as [$c, $n, $t]) {
                $this->acc[$c] = Account::create(['code' => $c, 'name' => $n, 'type' => $t, 'is_postable' => true, 'is_budgetable' => $t === 'expense', 'is_active' => true])->id;
            }
            $this->ids['bank'] = CashBankAccount::create(['name' => 'Main', 'kind' => 'bank', 'account_id' => $this->acc['1100'], 'currency' => 'TZS', 'is_active' => true])->id;
            app(PostingService::class)->post('2026-01-01', 'Opening', [['account_id' => $this->acc['1100'], 'debit' => 5000000], ['account_id' => $this->acc['3000'], 'credit' => 5000000]], $this->u['acc'], 'opening');
            AccountingSetting::current()->fill(['budget_release' => 'none', 'budget_control' => 'warn'])->save();
            $budget = Budget::create(['fiscal_year_id' => $year->id, 'title' => 'Budget 2026', 'phasing' => 'quarter', 'status' => 'approved', 'created_by' => $this->u['acc']->id]);
            $this->ids['item'] = BudgetItem::create(['budget_id' => $budget->id, 'department_id' => $dept->id, 'account_id' => $this->acc['5600'], 'description' => 'Stationery',
                'quantity' => 1, 'unit_cost' => 1000000, 'original_amount' => 1000000, 'revised_amount' => 1000000])->id;
            app(BudgetService::class)->record($this->ids['item'], 'approved', 1000000, $budget, 'Approved');
            $unit = Unit::create(['code' => 'REAM', 'name' => 'Ream']);
            $cat = ItemCategory::create(['code' => 'STA', 'name' => 'Stationery', 'type' => 'store', 'inventory_account_id' => $this->acc['1400'], 'expense_account_id' => $this->acc['5600']]);
            $this->ids['paper'] = Item::create(['code' => 'STA-0001', 'name' => 'A4 paper', 'item_category_id' => $cat->id, 'type' => 'store', 'use' => 'internal', 'unit_id' => $unit->id])->id;
            $this->ids['store'] = Store::create(['code' => 'MAIN', 'name' => 'Main store', 'kind' => 'main'])->id;
            $this->ids['dept'] = $dept->id;
            $sup = app(ManageSuppliers::class);
            foreach (['A' => 'Alpha Office Ltd', 'B' => 'Beta Paper Ltd'] as $k => $name) {
                $s = $sup->save(null, ['name' => $name, 'kind' => 'company', 'tin' => "10{$k}"], $this->u['acc']);
                $sup->bankApproved($s->bankAccounts()->create(['bank_name' => 'CRDB', 'account_name' => $name, 'account_number' => "0150{$k}", 'currency' => 'TZS', 'status' => 'pending']));
                $this->ids['sup'.$k] = $s->id;
            }
            $director = Role::where('name', 'director-general')->value('id');
            foreach (['purchase-requisition', 'quotation-award', 'purchase-order', 'goods-receipt'] as $p) {
                WorkflowTemplate::where('process', $p)->delete();
                WorkflowTemplate::create(['process' => $p, 'company_branch_id' => null, 'is_active' => true])
                    ->steps()->create(['step_order' => 1, 'name' => 'Director', 'approver_type' => 'role', 'role_id' => $director, 'allow_send_back' => true]);
            }
            WorkflowTemplate::whereIn('process', ['payment-voucher', 'payment-list'])->delete();
        });
        $emp = Employee::withoutGlobalScopes()->where('employee_no', 'H1')->value('id');
        $this->actingAs($admin)->postJson("/api/v1/hr/structure/departments/{$this->ids['dept']}/head", ['employee_id' => $emp])->assertOk();
        $this->u['head']->refresh();
    }

    private function approve(string $process): void
    {
        $id = WorkflowInstance::where('process', $process)->where('status', 'pending')->latest('id')->value('id');
        $this->actingAs($this->u['director-general'])->postJson("/api/v1/workflow/requests/{$id}/approve")->assertOk();
    }

    private function figures(): array
    {
        return $this->actingAs($this->u['acc'])->getJson("/api/v1/budgeting/items/{$this->ids['item']}")->json('data');
    }

    public function test_requisition_quotations_order_delivery_and_matched_bill(): void
    {
        $this->actingAs($this->u['acc'])->putJson('/api/v1/procurement/settings', ['clearing_account_id' => $this->acc['2150'], 'direct_limit' => 500000, 'min_quotes' => 2, 'match_tolerance_percent' => 0])->assertOk();
        $this->putJson('/api/v1/payments/settings', ['payable_account_id' => $this->acc['2100'], 'withholding_account_id' => null, 'require_attachment' => true])->assertOk();

        $req = $this->actingAs($this->u['head'])->postJson('/api/v1/procurement/requisitions', ['department_id' => $this->ids['dept'], 'store_id' => $this->ids['store'], 'title' => 'Paper for Q4', 'kind' => 'normal',
            'lines' => [['item_id' => $this->ids['paper'], 'description' => 'A4 paper 80gsm', 'quantity' => 60, 'estimated_unit_cost' => 13000, 'budget_item_id' => $this->ids['item']]]])
            ->assertCreated()->assertJsonPath('data.number', 'PRQ-2026-00001')->json('data.id');
        $this->postJson("/api/v1/procurement/requisitions/{$req}/submit")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame(780000.0, (float) $this->figures()['reserved']);
        $this->approve('purchase-requisition');

        $line = $this->actingAs($this->u['buyer'])->getJson("/api/v1/procurement/requisitions/{$req}")->assertJsonPath('data.status', 'approved')->assertJsonPath('data.can.source', true)->json('data.lines.0.id');
        $this->postJson("/api/v1/procurement/requisitions/{$req}/orders", ['supplier_id' => $this->ids['supA'], 'method' => 'direct', 'lines' => [['purchase_requisition_line_id' => $line, 'quantity' => 60, 'unit_price' => 12500]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['method']);
        $rfq = $this->postJson("/api/v1/procurement/requisitions/{$req}/rfqs", ['title' => 'Paper', 'closing_date' => '2026-11-25'])->assertCreated()->json('data.id');
        $qa = $this->postJson("/api/v1/procurement/rfqs/{$rfq}/quotations", ['supplier_id' => $this->ids['supA'], 'lines' => [['purchase_requisition_line_id' => $line, 'unit_price' => 12500]]])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/procurement/rfqs/{$rfq}/award", ['awards' => [['purchase_requisition_line_id' => $line, 'quotation_id' => $qa]]])->assertUnprocessable()->assertJsonValidationErrors(['comment']);
        $qb = $this->postJson("/api/v1/procurement/rfqs/{$rfq}/quotations", ['supplier_id' => $this->ids['supB'], 'delivery_days' => 7, 'lines' => [['purchase_requisition_line_id' => $line, 'unit_price' => 12000]]])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/procurement/rfqs/{$rfq}")->assertJsonCount(2, 'data.quotations')->assertJsonPath('data.quotations.0.supplier', 'Beta Paper Ltd');
        $this->get("/api/v1/procurement/rfqs/{$rfq}/pdf?supplier_id={$this->ids['supA']}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get("/api/v1/procurement/rfqs/{$rfq}/comparison/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson("/api/v1/procurement/rfqs/{$rfq}/award", ['awards' => [['purchase_requisition_line_id' => $line, 'quotation_id' => $qb]]])->assertOk()->assertJsonPath('data.status', 'pending');
        $this->approve('quotation-award');

        $po = $this->actingAs($this->u['buyer'])->getJson('/api/v1/procurement/orders')->assertJsonCount(1, 'data')->assertJsonPath('data.0.supplier', 'Beta Paper Ltd')->assertJsonPath('data.0.total', 720000)->json('data.0.id');
        $this->postJson("/api/v1/procurement/orders/{$po}/submit")->assertOk();
        $this->approve('purchase-order');
        $f = $this->figures();
        $this->assertSame([0.0, 720000.0, 280000.0], [(float) $f['reserved'], (float) $f['committed'], (float) $f['available']]);
        $this->actingAs($this->u['buyer'])->getJson("/api/v1/procurement/requisitions/{$req}")->assertJsonPath('data.status', 'ordered')->assertJsonPath('data.lines.0.remaining', 0);
        $this->get("/api/v1/procurement/orders/{$po}/pdf")->assertOk();

        $poLine = $this->actingAs($this->u['keeper'])->getJson("/api/v1/procurement/orders/{$po}")->assertJsonPath('data.can.receive', true)->json('data.lines.0.id');
        $grn = $this->postJson("/api/v1/procurement/orders/{$po}/receipts", ['delivery_note' => 'DN-77', 'received_on' => '2026-11-18',
            'lines' => [['purchase_order_line_id' => $poLine, 'delivered_quantity' => 60, 'accepted_quantity' => 50]]])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.rejection_reason']);
        $grn = $this->postJson("/api/v1/procurement/orders/{$po}/receipts", ['delivery_note' => 'DN-77', 'received_on' => '2026-11-18',
            'lines' => [['purchase_order_line_id' => $poLine, 'delivered_quantity' => 60, 'accepted_quantity' => 50, 'rejection_reason' => 'Wet boxes']]])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/procurement/receipts/{$grn}/submit")->assertOk();
        $this->get("/api/v1/procurement/receipts/{$grn}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->approve('goods-receipt');
        $f = $this->figures();
        $this->assertSame([600000.0, 120000.0], [(float) $f['actual'], (float) $f['committed']]);
        $bill = $this->actingAs($this->u['acc'])->getJson("/api/v1/procurement/receipts/{$grn}")->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.matched', false)
            ->assertJsonPath('data.can.invoice', true)->json('data.payment_request');
        $this->assertNotNull($bill);
        $this->getJson("/api/v1/procurement/orders/{$po}")->assertJsonPath('data.status', 'partial')->assertJsonPath('data.lines.0.due', 10);
        $stock = app(CompanyContext::class)->run($this->company, fn () => \Modules\Inventory\Models\StockBalance::where('store_id', $this->ids['store'])->where('item_id', $this->ids['paper'])->first());
        $this->assertSame([50.0, 600000.0], [$stock->quantity, $stock->value]);

        $this->postJson("/api/v1/payments/requests/{$bill['id']}/voucher", ['cash_bank_account_id' => $this->ids['bank'], 'date' => '2026-11-18'])->assertUnprocessable()->assertJsonValidationErrors(['request']);
        $this->putJson("/api/v1/procurement/receipts/{$grn}/invoice", ['supplier_invoice' => 'INV-889', 'invoice_date' => '2026-11-18', 'invoice_amount' => 650000])->assertOk()->assertJsonPath('data.matched', false);
        $this->putJson("/api/v1/procurement/receipts/{$grn}/invoice", ['supplier_invoice' => 'INV-889', 'invoice_date' => '2026-11-18', 'invoice_amount' => 600000])->assertOk()->assertJsonPath('data.matched', true);
        $v = $this->postJson("/api/v1/payments/requests/{$bill['id']}/voucher", ['cash_bank_account_id' => $this->ids['bank'], 'date' => '2026-11-18'])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/payments/vouchers/{$v}")->assertJsonPath('data.status', 'approved')->assertJsonPath('data.payees.0.name', 'Beta Paper Ltd')->assertJsonPath('data.payees.0.destination', 'CRDB 0150B');
        $this->assertSame(600000.0, (float) $this->figures()['actual']);
        $tb = collect($this->getJson('/api/v1/reports/finance/trial-balance')->json('data.rows'))->keyBy('code');
        $this->assertNull($tb['2150']['debit'] ?? null);
        $this->assertSame(600000, $tb['1400']['debit']);
        $this->assertSame(600000, $tb['2100']['credit']);

        $grn2 = $this->actingAs($this->u['keeper'])->postJson("/api/v1/procurement/orders/{$po}/receipts", ['received_on' => '2026-11-19',
            'lines' => [['purchase_order_line_id' => $poLine, 'delivered_quantity' => 10]]])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/procurement/receipts/{$grn2}/submit")->assertOk();
        $this->approve('goods-receipt');
        $f = $this->figures();
        $this->assertSame([720000.0, 0.0], [(float) $f['actual'], (float) $f['committed']]);
        $this->actingAs($this->u['buyer'])->getJson("/api/v1/procurement/orders/{$po}")->assertJsonPath('data.status', 'received');
        $this->artisan('budget:check-balances')->assertSuccessful();
    }
}
