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
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Accounting\Services\PostingService;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Services\BudgetService;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\ItemCategory;
use Modules\Inventory\Models\Store;
use Modules\Inventory\Models\Unit;
use Modules\Payments\Models\PaymentRequest;
use Modules\Procurement\Actions\ManageSuppliers;
use Modules\Procurement\Models\ProcurementMethod;
use Modules\Procurement\Models\Supplier;
use Modules\Reporting\Support\FinanceReports;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class TenderContractTest extends TestCase
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
            foreach ([['1100', 'Bank', 'asset'], ['1400', 'Stock', 'asset'], ['2100', 'Payables', 'liability'], ['2150', 'Goods received not billed', 'liability'], ['3000', 'Fund', 'equity'], ['5600', 'Office supplies', 'expense'], ['2160', 'Retention held', 'liability'], ['4900', 'Damages recovered', 'revenue']] as [$c, $n, $t]) {
                $this->acc[$c] = Account::create(['code' => $c, 'name' => $n, 'type' => $t, 'is_postable' => true, 'is_budgetable' => $t === 'expense', 'is_active' => true])->id;
            }
            $this->ids['bank'] = CashBankAccount::create(['name' => 'Main', 'kind' => 'bank', 'account_id' => $this->acc['1100'], 'currency' => 'TZS', 'is_active' => true])->id;
            app(PostingService::class)->post('2026-01-01', 'Opening', [['account_id' => $this->acc['1100'], 'debit' => 5000000], ['account_id' => $this->acc['3000'], 'credit' => 5000000]], $this->u['acc'], 'opening');
            AccountingSetting::current()->fill(['budget_release' => 'none', 'budget_control' => 'warn'])->save();
            $budget = Budget::create(['fiscal_year_id' => $year->id, 'title' => 'Budget 2026', 'phasing' => 'quarter', 'status' => 'approved', 'created_by' => $this->u['acc']->id]);
            $this->ids['item'] = BudgetItem::create(['budget_id' => $budget->id, 'department_id' => $dept->id, 'account_id' => $this->acc['5600'], 'description' => 'Stationery',
                'quantity' => 1, 'unit_cost' => 5000000, 'original_amount' => 5000000, 'revised_amount' => 5000000])->id;
            app(BudgetService::class)->record($this->ids['item'], 'approved', 5000000, $budget, 'Approved');
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
            $this->assertSame(6, WorkflowTemplate::whereIn('process', ['tender-document', 'tender-evaluation', 'tender-award', 'contract-vetting', 'contract-variation', 'contract-certificate'])->count());
            WorkflowTemplate::whereIn('process', ['tender-document', 'tender-evaluation', 'tender-award', 'contract-vetting', 'contract-variation', 'contract-certificate', 'purchase-order'])->delete();
            foreach (['purchase-requisition'] as $p) {
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

    private function requisition(string $title, array $lines): int
    {
        $req = $this->actingAs($this->u['head'])->postJson('/api/v1/procurement/requisitions', ['department_id' => $this->ids['dept'], 'store_id' => $this->ids['store'], 'title' => $title, 'kind' => 'normal',
            'lines' => $lines])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/procurement/requisitions/{$req}/submit")->assertOk();
        $this->approve('purchase-requisition');

        return $req;
    }

    private function method(string $code): int
    {
        return (int) collect($this->actingAs($this->u['buyer'])->getJson('/api/v1/procurement/methods')->assertOk()->json('data'))->firstWhere('code', $code)['id'];
    }

    public function test_goods_tender_from_sealed_bids_to_contract_and_call_off(): void
    {
        $this->actingAs($this->u['acc'])->putJson('/api/v1/procurement/settings', ['clearing_account_id' => $this->acc['2150'], 'direct_limit' => 500000, 'min_quotes' => 2, 'match_tolerance_percent' => 0])->assertOk();
        $req = $this->requisition('Paper for 2027', [['item_id' => $this->ids['paper'], 'description' => 'A4 paper 80gsm', 'quantity' => 60, 'estimated_unit_cost' => 13000, 'budget_item_id' => $this->ids['item']]]);
        $this->assertSame(780000.0, (float) $this->figures()['reserved']);

        $t = $this->actingAs($this->u['buyer'])->postJson("/api/v1/procurement/requisitions/{$req}/tenders", ['procurement_method_id' => $this->method('OT'), 'title' => 'Supply of paper',
            'document_type' => 'goods', 'closing_at' => '2026-11-20 10:00:00', 'criteria' => [['name' => 'Valid business licence', 'kind' => 'mandatory']]])->assertUnprocessable()->assertJsonValidationErrors(['method_reason']);
        $t = $this->postJson("/api/v1/procurement/requisitions/{$req}/tenders", ['procurement_method_id' => $this->method('OT'), 'title' => 'Supply of paper', 'document_type' => 'goods',
            'method_reason' => 'Annual supply; open competition preferred', 'closing_at' => '2026-11-20 10:00:00', 'criteria' => [['name' => 'Valid business licence', 'kind' => 'mandatory']]])->assertOk()->json('data.id');
        $this->postJson("/api/v1/procurement/tenders/{$t}/submit")->assertOk()->assertJsonPath('data.status', 'published');
        $line = $this->getJson("/api/v1/procurement/tenders/{$t}")->json('data.lines.0.id');
        $this->postJson("/api/v1/procurement/tenders/{$t}/clarifications", ['kind' => 'amendment', 'answer' => 'Closing moved', 'closing_at' => '2026-11-21 10:00:00'])->assertOk();
        foreach (['supA' => 12000, 'supB' => 12500] as $k => $price) {
            $this->postJson("/api/v1/procurement/tenders/{$t}/bids", ['supplier_id' => $this->ids[$k], 'lines' => [['tender_line_id' => $line, 'unit_price' => $price]]])->assertOk();
        }
        $sealed = $this->getJson("/api/v1/procurement/tenders/{$t}")->json('data');
        $this->assertNull($sealed['bids'][0]['prices']);
        $this->assertNull($sealed['bids'][0]['read_out_total']);
        $this->postJson("/api/v1/procurement/tenders/{$t}/open")->assertUnprocessable();

        $this->travelTo('2026-11-21 11:00:00');
        $this->postJson("/api/v1/procurement/tenders/{$t}/bids", ['supplier_id' => $this->ids['supA'], 'lines' => [['tender_line_id' => $line, 'unit_price' => 1]]])->assertUnprocessable();
        $this->postJson("/api/v1/procurement/tenders/{$t}/open", ['attendees' => 'Procurement, both bidders'])->assertOk()->assertJsonPath('data.status', 'opened');
        $this->postJson("/api/v1/procurement/tenders/{$t}/members", ['user_id' => $this->u['head']->id, 'role' => 'evaluation'])->assertUnprocessable();
        $this->postJson("/api/v1/procurement/tenders/{$t}/members", ['user_id' => $this->u['buyer']->id, 'role' => 'evaluation'])->assertUnprocessable();
        $this->postJson("/api/v1/procurement/tenders/{$t}/members", ['user_id' => $this->u['acc']->id, 'role' => 'evaluation', 'chair' => true])->assertOk();

        $opened = $this->actingAs($this->u['acc'])->getJson("/api/v1/procurement/tenders/{$t}")->json('data');
        $this->assertSame(720000, (int) $opened['bids'][0]['read_out_total']);
        $criterion = $opened['criteria'][0]['id'];
        $bids = collect($opened['bids'])->keyBy('supplier_id');
        $this->postJson("/api/v1/procurement/tenders/{$t}/bids/{$bids[$this->ids['supA']]['id']}/scores", ['scores' => [['tender_criterion_id' => $criterion, 'passed' => true]]])->assertUnprocessable();
        $this->postJson("/api/v1/procurement/tenders/{$t}/declare", ['conflict' => false])->assertOk();
        foreach ($bids as $b) {
            $this->postJson("/api/v1/procurement/tenders/{$t}/bids/{$b['id']}/scores", ['scores' => [['tender_criterion_id' => $criterion, 'passed' => true]]])->assertOk();
        }
        $this->actingAs($this->u['buyer'])->postJson("/api/v1/procurement/tenders/{$t}/evaluation", ['summary' => 'Both responsive'])->assertOk()->assertJsonPath('data.status', 'evaluated');
        $ranking = $this->getJson("/api/v1/procurement/tenders/{$t}")->json('data.ranking');
        $this->assertSame('Alpha Office Ltd', $ranking['Lot 1'][0]['supplier']);
        $this->postJson("/api/v1/procurement/tenders/{$t}/award", ['awards' => [['lot' => 'Lot 1', 'tender_bid_id' => $bids[$this->ids['supA']]['id'], 'amount' => 700000]]])->assertUnprocessable();
        $this->postJson("/api/v1/procurement/tenders/{$t}/award", ['awards' => [['lot' => 'Lot 1', 'tender_bid_id' => $bids[$this->ids['supA']]['id']]]])->assertOk()->assertJsonPath('data.status', 'standstill');
        $this->postJson("/api/v1/procurement/tenders/{$t}/complaints", ['supplier_id' => $this->ids['supB'], 'description' => 'Our price was close'])->assertOk();
        $this->travelTo('2026-11-29 09:00:00');
        $this->postJson("/api/v1/procurement/tenders/{$t}/end-standstill")->assertUnprocessable();
        $complaint = $this->getJson("/api/v1/procurement/tenders/{$t}")->json('data.complaints.0.id');
        $this->actingAs($this->u['acc'])->postJson("/api/v1/procurement/tenders/{$t}/complaints/{$complaint}/decide", ['outcome' => 'dismissed', 'decision' => 'Lowest responsive bid won'])->assertOk();
        $this->actingAs($this->u['buyer'])->postJson("/api/v1/procurement/tenders/{$t}/end-standstill")->assertOk()->assertJsonPath('data.status', 'awarded');
        $award = $this->getJson("/api/v1/procurement/tenders/{$t}")->json('data.awards.0.id');
        $this->postJson("/api/v1/procurement/tenders/{$t}/awards/{$award}/contract", ['type' => 'running', 'start_date' => '2026-12-01', 'end_date' => '2027-11-30'])->assertUnprocessable();
        $this->postJson("/api/v1/procurement/tenders/{$t}/awards/{$award}/respond", ['accepted' => true])->assertOk();
        $con = $this->postJson("/api/v1/procurement/tenders/{$t}/awards/{$award}/contract", ['type' => 'running', 'start_date' => '2026-12-01', 'end_date' => '2027-11-30',
            'security_type' => 'bank_guarantee', 'security_amount' => 72000, 'security_expiry' => '2027-12-31'])->assertOk()->json('data.id');
        $this->postJson("/api/v1/procurement/contracts/{$con}/submit")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame('contracted', $this->getJson("/api/v1/procurement/tenders/{$t}")->json('data.status'));
        $f = $this->figures();
        $this->assertSame([0.0, 720000.0], [(float) $f['reserved'], (float) $f['committed']]);

        $cl = $this->getJson("/api/v1/procurement/contracts/{$con}")->json('data.lines.0.id');
        $this->postJson("/api/v1/procurement/contracts/{$con}/orders", ['lines' => [['contract_line_id' => $cl, 'quantity' => 61]]])->assertUnprocessable();
        $po = $this->postJson("/api/v1/procurement/contracts/{$con}/orders", ['lines' => [['contract_line_id' => $cl, 'quantity' => 30]]])->assertOk()->json('data.id');
        $this->postJson("/api/v1/procurement/orders/{$po}/submit")->assertOk();
        $this->assertSame(720000.0, (float) $this->figures()['committed']);
        $c = $this->getJson("/api/v1/procurement/contracts/{$con}")->json('data');
        $this->assertSame(30, (int) $c['lines'][0]['remaining']);
        $this->assertEquals(360000, $c['figures']['balance']);
        app(CompanyContext::class)->run($this->company, function () use ($con) {
            $this->assertEquals(360000, app(\Modules\Budgeting\Services\BudgetService::class)->heldBy(\Modules\Procurement\Models\Contract::find($con)));
            $r = app(FinanceReports::class);
            $this->assertSame('Alpha Office Ltd', collect($r->build('tender-awards', ['from' => '2026-01-01', 'to' => '2026-12-31'])['rows'])->first()['supplier']);
            $this->assertEquals(60000, collect($r->build('tender-awards', ['from' => '2026-01-01', 'to' => '2026-12-31'])['rows'])->last()['saving']);
            $this->assertCount(2, $r->build('bid-opening', ['tender_id' => \Modules\Procurement\Models\Tender::value('id')])['rows']);
            $this->assertEquals(360000, collect($r->build('contract-register', ['status' => null])['rows'])->last()['balance']);
        });
    }

    public function test_works_contract_certificates_retention_damages_variations_and_close(): void
    {
        $this->actingAs($this->u['acc'])->putJson('/api/v1/procurement/settings', ['clearing_account_id' => $this->acc['2150'], 'direct_limit' => 500000, 'min_quotes' => 2, 'match_tolerance_percent' => 0,
            'retention_account_id' => $this->acc['2160'], 'damages_account_id' => $this->acc['4900'], 'variation_limit_percent' => 15])->assertOk();
        $this->putJson('/api/v1/payments/settings', ['payable_account_id' => $this->acc['2100'], 'withholding_account_id' => null, 'require_attachment' => false])->assertOk();
        $req = $this->requisition('Office renovation', [['description' => 'Renovation works', 'quantity' => 1, 'estimated_unit_cost' => 2000000, 'budget_item_id' => $this->ids['item']]]);
        $t = $this->actingAs($this->u['buyer'])->postJson("/api/v1/procurement/requisitions/{$req}/tenders", ['procurement_method_id' => $this->method('SS'), 'title' => 'Renovation', 'document_type' => 'small_works',
            'method_reason' => 'Only contractor on the approved list', 'closing_at' => '2026-11-19 10:00:00'])->assertOk()->json('data.id');
        $this->postJson("/api/v1/procurement/tenders/{$t}/submit")->assertOk();
        $line = $this->getJson("/api/v1/procurement/tenders/{$t}")->json('data.lines.0.id');
        $this->postJson("/api/v1/procurement/tenders/{$t}/bids", ['supplier_id' => $this->ids['supB'], 'lines' => [['tender_line_id' => $line, 'unit_price' => 2100000]]])->assertOk();
        $this->travelTo('2026-11-19 11:00:00');
        $this->postJson("/api/v1/procurement/tenders/{$t}/open")->assertOk();
        $this->postJson("/api/v1/procurement/tenders/{$t}/members", ['user_id' => $this->u['acc']->id, 'role' => 'evaluation'])->assertOk();
        $this->actingAs($this->u['acc'])->postJson("/api/v1/procurement/tenders/{$t}/declare", ['conflict' => false])->assertOk();
        $this->actingAs($this->u['buyer'])->postJson("/api/v1/procurement/tenders/{$t}/evaluation", ['negotiation_minutes' => 'Agreed 2,000,000'])->assertOk();
        $bid = $this->getJson("/api/v1/procurement/tenders/{$t}")->json('data.bids.0.id');
        $this->postJson("/api/v1/procurement/tenders/{$t}/award", ['awards' => [['lot' => 'Lot 1', 'tender_bid_id' => $bid, 'amount' => 2000000]]])->assertOk()->assertJsonPath('data.status', 'awarded');
        $award = $this->getJson("/api/v1/procurement/tenders/{$t}")->json('data.awards.0.id');
        $this->postJson("/api/v1/procurement/tenders/{$t}/awards/{$award}/respond", ['accepted' => true])->assertOk();
        $con = $this->postJson("/api/v1/procurement/tenders/{$t}/awards/{$award}/contract", ['type' => 'lump_sum', 'start_date' => '2026-11-20', 'end_date' => '2027-02-28', 'retention_percent' => 10,
            'damages_percent_per_day' => 0.1, 'damages_cap_percent' => 10, 'milestones' => [['name' => 'Retention release after defects period', 'kind' => 'retention', 'amount' => 100000]]])->assertOk()->json('data.id');
        $c = $this->getJson("/api/v1/procurement/contracts/{$con}")->json('data');
        $this->assertSame('works', $c['category']);
        $this->assertEquals(2000000, $c['value']);
        $this->postJson("/api/v1/procurement/contracts/{$con}/submit")->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson("/api/v1/procurement/contracts/{$con}/orders", ['lines' => [['contract_line_id' => $c['lines'][0]['id'], 'quantity' => 1]]])->assertUnprocessable();

        $this->postJson("/api/v1/procurement/contracts/{$con}/certificates", ['date' => '2026-11-30', 'gross' => 3000000])->assertUnprocessable();
        $this->postJson("/api/v1/procurement/contracts/{$con}/certificates", ['date' => '2026-11-30', 'gross' => 1000000, 'days_late' => 5, 'percent_complete' => 50, 'period' => 'November'])
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $c = $this->getJson("/api/v1/procurement/contracts/{$con}")->json('data');
        $cert = $c['certificates'][0];
        $this->assertEquals([100000, 10000, 890000], [$cert['retention'], $cert['damages'], $cert['net']]);
        $this->assertEquals(['certified' => 1000000, 'retained' => 100000, 'balance' => 1000000], array_intersect_key($c['figures'], array_flip(['certified', 'retained', 'balance'])));
        $this->assertSame(50, $c['percent_complete']);
        app(CompanyContext::class)->run($this->company, function () use ($cert) {
            $bill = PaymentRequest::find($cert['payment_request']['id']);
            $this->assertEquals([890000, 'approved'], [$bill->total, $bill->status]);
            $j = JournalEntry::where('source_type', (new \Modules\Procurement\Models\ContractCertificate)->getMorphClass())->firstOrFail();
            $this->assertEquals(round($j->lines()->sum('debit'), 2), round($j->lines()->sum('credit'), 2));
        });
        $f = $this->figures();
        $this->assertEquals(1000000, (float) $f['committed']);
        $this->assertEquals(1000000, (float) $f['actual']);

        $this->postJson("/api/v1/procurement/contracts/{$con}/variations", ['kind' => 'addendum', 'amount' => 250000, 'budget_item_id' => $this->ids['item'], 'reason' => 'Extra partition'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/procurement/contracts/{$con}/variations", ['kind' => 'addendum', 'amount' => 100000, 'budget_item_id' => $this->ids['item'], 'reason' => 'More'])->assertUnprocessable();
        $this->postJson("/api/v1/procurement/contracts/{$con}/variations", ['kind' => 'extension', 'new_end_date' => '2027-03-31', 'reason' => 'Rains'])->assertOk();
        $c = $this->getJson("/api/v1/procurement/contracts/{$con}")->json('data');
        $this->assertSame('2027-03-31', $c['end_date']);
        $this->assertEquals(1250000, $c['figures']['balance']);
        $this->assertEquals(1250000, (float) $this->figures()['committed']);

        $this->postJson("/api/v1/procurement/contracts/{$con}/close", ['note' => 'Done'])->assertUnprocessable();
        $this->postJson("/api/v1/procurement/contracts/{$con}/milestones/{$c['milestones'][0]['id']}/pay")->assertOk()->assertJsonPath('data.status', 'requested');
        $this->postJson("/api/v1/procurement/contracts/{$con}/close", ['note' => 'Works finished; balance not needed'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertEquals(0, (float) $this->figures()['committed']);
    }
}
