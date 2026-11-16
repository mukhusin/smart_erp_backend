<?php

namespace Modules\Payments\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Accounting\Services\PostingService;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Services\BudgetService;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private User $finance;

    private User $director;

    private User $head;

    private Department $pharmacy;

    private array $acc = [];

    private int $bank;

    private int $item;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-11-18 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'accounting'], ['slug' => 'budgeting'], ['slug' => 'payments']]]);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->run($this->company, function () {
            Role::findOrCreate('accountant', 'web')->syncPermissions(['accounting.view', 'accounting.journals', 'accounting.manage', 'budgeting.view', 'budgeting.manage',
                'payments.view', 'payments.vouchers', 'payments.pay', 'payments.manage']);
            $this->finance = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('accountant');
            $this->director = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('director-general');
            $this->pharmacy = Department::create(['name' => 'Pharmacy', 'is_active' => true]);
            $this->head = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
            Employee::create(['employee_no' => 'PH1', 'fname' => 'Pharm', 'lname' => 'Head', 'gender' => 'Female', 'department_id' => $this->pharmacy->id,
                'user_id' => $this->head->id, 'employment_status' => 'confirmed', 'date_of_birth' => '1990-11-15']);
            $year = app(FiscalCalendar::class)->createYear('2026-01-01');
            foreach ([['1100', 'CRDB', 'asset'], ['2100', 'Payables', 'liability'], ['2200', 'Withholding tax', 'liability'], ['3000', 'Fund balance', 'equity'], ['5100', 'Medical supplies', 'expense']] as [$code, $name, $type]) {
                $this->acc[$code] = Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'is_postable' => true, 'is_budgetable' => $type === 'expense', 'is_active' => true])->id;
            }
            $this->bank = CashBankAccount::create(['name' => 'CRDB main', 'kind' => 'bank', 'account_id' => $this->acc['1100'], 'currency' => 'TZS', 'is_active' => true])->id;
            app(PostingService::class)->post('2026-01-01', 'Opening', [['account_id' => $this->acc['1100'], 'debit' => 1000000], ['account_id' => $this->acc['3000'], 'credit' => 1000000]], $this->finance, 'opening');
            AccountingSetting::current()->fill(['budget_release' => 'none', 'budget_control' => 'warn'])->save();
            $budget = Budget::create(['fiscal_year_id' => $year->id, 'title' => 'Budget 2026', 'phasing' => 'quarter', 'status' => 'approved', 'created_by' => $this->finance->id]);
            $this->item = BudgetItem::create(['budget_id' => $budget->id, 'department_id' => $this->pharmacy->id, 'account_id' => $this->acc['5100'], 'description' => 'Medicines',
                'quantity' => 1, 'unit_cost' => 500000, 'original_amount' => 500000, 'revised_amount' => 500000])->id;
            app(BudgetService::class)->record($this->item, 'approved', 500000, $budget, 'Approved');
            $accountant = Role::where('name', 'accountant')->value('id');
            $director = Role::where('name', 'director-general')->value('id');
            foreach (['payment-request' => [[$accountant, null]], 'payment-voucher' => [[$director, null], [$accountant, 1000000]], 'payment-list' => [[$director, null]], 'payment-void' => [[$director, null]]] as $process => $steps) {
                WorkflowTemplate::where('process', $process)->delete();
                $t = WorkflowTemplate::create(['process' => $process, 'company_branch_id' => null, 'is_active' => true]);
                foreach ($steps as $i => [$role, $min]) {
                    $t->steps()->create(['step_order' => $i + 1, 'name' => 'Step '.($i + 1), 'approver_type' => 'role', 'role_id' => $role, 'min_amount' => $min, 'allow_send_back' => true]);
                }
            }
        });
        $this->giveActiveContracts($this->company);
        $emp = Employee::withoutGlobalScopes()->where('employee_no', 'PH1')->value('id');
        $this->actingAs($this->admin)->postJson("/api/v1/hr/structure/departments/{$this->pharmacy->id}/head", ['employee_id' => $emp])->assertOk();
        $this->head->refresh();
    }

    private function approve(string $process, User $as): void
    {
        $id = WorkflowInstance::where('process', $process)->where('status', 'pending')->latest('id')->value('id');
        $this->actingAs($as)->postJson("/api/v1/workflow/requests/{$id}/approve")->assertOk();
    }

    private function item(): array
    {
        return $this->actingAs($this->finance)->getJson("/api/v1/budgeting/items/{$this->item}")->json('data');
    }

    public function test_request_voucher_and_payment_list(): void
    {
        $this->actingAs($this->head)->getJson('/api/v1/payments/lookups')->assertOk()->assertJsonPath('data.departments.0.name', 'Pharmacy')->assertJsonPath('data.banks.0.balance', null);
        $this->getJson('/api/v1/payments/budget-lines?department_id='.$this->pharmacy->id)->assertJsonPath('data.0.available', 500000);

        $body = ['department_id' => $this->pharmacy->id, 'title' => 'Essential medicines', 'lines' => [['budget_item_id' => $this->item, 'amount' => 300000]],
            'payees' => [['name' => 'MSD', 'method' => 'bank', 'bank_name' => 'NMB', 'account_number' => '2001', 'amount' => 200000], ['name' => 'Juma', 'kind' => 'staff', 'method' => 'mobile', 'mobile_number' => '0712000000', 'amount' => 50000]]];
        $this->postJson('/api/v1/payments/requests', $body)->assertUnprocessable()->assertJsonValidationErrors(['payees' => 'add up']);
        $body['payees'][1]['amount'] = 100000;
        $id = $this->postJson('/api/v1/payments/requests', $body)->assertCreated()->assertJsonPath('data.number', 'PR-2026-00001')->json('data.id');
        $this->postJson("/api/v1/payments/requests/{$id}/submit")->assertUnprocessable()->assertJsonValidationErrors(['attachments']);
        $this->post("/api/v1/payments/requests/{$id}/attachments", ['file' => UploadedFile::fake()->create('invoice.pdf', 50, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->postJson("/api/v1/payments/requests/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame(200000.0, (float) $this->item()['available']);
        $this->assertSame(300000.0, (float) $this->item()['reserved']);

        $this->approve('payment-request', $this->finance);
        $this->assertSame(300000.0, (float) $this->item()['committed']);
        $this->actingAs($this->head)->getJson("/api/v1/payments/requests/{$id}")->assertJsonPath('data.status', 'approved')->assertJsonPath('data.can.voucher', false);

        $payees = $this->actingAs($this->finance)->getJson("/api/v1/payments/requests/{$id}")->assertJsonPath('data.can.voucher', true)->json('data.payees');
        $v = $this->postJson("/api/v1/payments/requests/{$id}/voucher", ['cash_bank_account_id' => $this->bank, 'date' => '2026-11-18', 'withholding' => [['payee_id' => $payees[0]['id'], 'amount' => 10000]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['settings']);
        $this->putJson('/api/v1/payments/settings', ['payable_account_id' => $this->acc['2100'], 'withholding_account_id' => $this->acc['2200'], 'require_attachment' => true])->assertOk();
        $v = $this->postJson("/api/v1/payments/requests/{$id}/voucher", ['cash_bank_account_id' => $this->bank, 'date' => '2026-11-18', 'withholding' => [['payee_id' => $payees[0]['id'], 'amount' => 10000]]])
            ->assertCreated()->assertJsonPath('data.number', 'PV-2026-00001')->json('data.id');
        $this->approve('payment-voucher', $this->director);
        $this->actingAs($this->finance)->getJson("/api/v1/payments/vouchers/{$v}")->assertJsonPath('data.status', 'approved')->assertJsonPath('data.net', 290000)->assertJsonPath('data.withholding', 10000);
        $item = $this->item();
        $this->assertSame([300000.0, 0.0, 0.0, 200000.0], [(float) $item['actual'], (float) $item['committed'], (float) $item['reserved'], (float) $item['available']]);

        $this->postJson("/api/v1/payments/vouchers/{$v}/void", ['reason' => 'Wrong tax'])->assertOk()->assertJsonPath('data.status', 'voided');
        $item = $this->item();
        $this->assertSame([0.0, 300000.0], [(float) $item['actual'], (float) $item['committed']]);
        $this->getJson("/api/v1/payments/requests/{$id}")->assertJsonPath('data.status', 'approved')->assertJsonPath('data.payees.0.withholding', 0);

        $v2 = $this->postJson("/api/v1/payments/requests/{$id}/voucher", ['cash_bank_account_id' => $this->bank, 'date' => '2026-11-18'])->assertCreated()->json('data.id');
        $this->assertSame(1, WorkflowInstance::where('process', 'payment-voucher')->where('status', 'pending')->first()->steps()->count());
        $this->approve('payment-voucher', $this->director);
        $this->actingAs($this->finance)->getJson('/api/v1/payments/vouchers?payable=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $v2);

        $list = $this->postJson('/api/v1/payments/lists', ['cash_bank_account_id' => $this->bank, 'method' => 'bank', 'date' => '2026-11-18', 'voucher_ids' => [$v2]])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson('/api/v1/payments/lists', ['cash_bank_account_id' => $this->bank, 'method' => 'bank', 'date' => '2026-11-18', 'voucher_ids' => [$v2]])->assertUnprocessable();
        $this->getJson("/api/v1/payments/lists/{$list}")->assertJsonPath('data.total', 300000)->assertJsonCount(2, 'data.items')->assertJsonPath('data.bank_balance', 1000000);
        $this->approve('payment-list', $this->director);
        $items = $this->actingAs($this->finance)->getJson("/api/v1/payments/lists/{$list}")->assertJsonPath('data.status', 'approved')->assertJsonPath('data.bank_balance', 700000)
            ->assertJsonPath('data.can.references', true)->json('data.items');
        $this->putJson("/api/v1/payments/lists/{$list}/references", ['references' => [['id' => $items[0]['id'], 'reference' => 'FT2611-001']]])->assertOk();
        $this->getJson("/api/v1/payments/vouchers/{$v2}")->assertJsonPath('data.status', 'paid')->assertJsonPath('data.payees.0.reference', 'FT2611-001');
        $this->actingAs($this->head)->getJson("/api/v1/payments/requests/{$id}")->assertJsonPath('data.status', 'paid');

        $this->actingAs($this->finance)->postJson("/api/v1/payments/lists/{$list}/items/{$items[0]['id']}/fail", ['status' => 'failed', 'reason' => 'Wrong account number'])->assertOk();
        $this->getJson("/api/v1/payments/lists/{$list}")->assertJsonPath('data.items.0.status', 'failed')->assertJsonPath('data.bank_balance', 900000)->assertJsonPath('data.items.0.can_fail', false);
        $this->getJson("/api/v1/payments/vouchers/{$v2}")->assertJsonPath('data.status', 'approved')->assertJsonPath('data.payees.0.payment_status', 'Failed');
        $this->getJson('/api/v1/payments/vouchers?payable=1')->assertJsonCount(1, 'data');
        $list2 = $this->postJson('/api/v1/payments/lists', ['cash_bank_account_id' => $this->bank, 'method' => 'bank', 'date' => '2026-11-18', 'voucher_ids' => [$v2]])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/payments/lists/{$list2}")->assertJsonCount(1, 'data.items')->assertJsonPath('data.total', 200000);
        $this->approve('payment-list', $this->director);
        $this->actingAs($this->finance)->getJson("/api/v1/payments/vouchers/{$v2}")->assertJsonPath('data.status', 'paid')->assertJsonPath('data.can.void', true);

        $this->postJson("/api/v1/payments/vouchers/{$v2}/void", ['reason' => 'Duplicate payment, refunded'])->assertOk()->assertJsonPath('data.status', 'void_pending');
        $this->approve('payment-void', $this->director);
        $this->actingAs($this->finance)->getJson("/api/v1/payments/vouchers/{$v2}")->assertJsonPath('data.status', 'voided');
        $this->getJson("/api/v1/payments/lists/{$list2}")->assertJsonPath('data.bank_balance', 1000000)->assertJsonPath('data.items.0.status', 'returned');
        $item = $this->item();
        $this->assertSame([0.0, 300000.0], [(float) $item['actual'], (float) $item['committed']]);
        $this->getJson("/api/v1/payments/requests/{$id}")->assertJsonPath('data.status', 'approved');
        $this->actingAs($this->finance)->getJson('/api/v1/accounting/reports/trial-balance')->assertOk();
        $this->artisan('budget:check-balances')->assertSuccessful();
    }

    public function test_block_mode_refuses_over_budget_and_withdraw_releases(): void
    {
        app(CompanyContext::class)->run($this->company, fn () => AccountingSetting::current()->fill(['budget_control' => 'block'])->save());
        $this->actingAs($this->finance)->putJson('/api/v1/payments/settings', ['payable_account_id' => $this->acc['2100'], 'withholding_account_id' => null, 'require_attachment' => false])->assertOk();
        $body = ['department_id' => $this->pharmacy->id, 'title' => 'Too much', 'lines' => [['budget_item_id' => $this->item, 'amount' => 600000]],
            'payees' => [['name' => 'MSD', 'method' => 'cheque', 'amount' => 600000]]];
        $id = $this->actingAs($this->head)->postJson('/api/v1/payments/requests', $body)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payments/requests/{$id}/submit")->assertUnprocessable()->assertJsonValidationErrors(['budget']);
        $body['lines'][0]['amount'] = $body['payees'][0]['amount'] = 400000;
        $this->putJson("/api/v1/payments/requests/{$id}", $body)->assertOk();
        $this->postJson("/api/v1/payments/requests/{$id}/submit")->assertOk();
        $this->assertSame(100000.0, (float) $this->item()['available']);
        $this->actingAs($this->head)->postJson("/api/v1/payments/requests/{$id}/withdraw")->assertOk()->assertJsonPath('data.status', 'draft');
        $this->assertSame(500000.0, (float) $this->item()['available']);
        $this->actingAs($this->director)->getJson("/api/v1/payments/requests/{$id}")->assertForbidden();
        $this->actingAs($this->head)->deleteJson("/api/v1/payments/requests/{$id}")->assertOk();
    }

    public function test_staff_member_requests_payment_for_own_department_from_self_service(): void
    {
        [$staff, $lab] = app(CompanyContext::class)->run($this->company, function () {
            $lab = Department::create(['name' => 'Laboratory', 'is_active' => true]);
            $staff = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
            Employee::create(['employee_no' => 'ST1', 'fname' => 'Juma', 'lname' => 'Staff', 'gender' => 'Male', 'department_id' => $this->pharmacy->id,
                'user_id' => $staff->id, 'employment_status' => 'confirmed', 'date_of_birth' => '1992-01-01']);

            return [$staff, $lab];
        });
        $this->actingAs($this->finance)->putJson('/api/v1/payments/settings', ['payable_account_id' => $this->acc['2100'], 'withholding_account_id' => null, 'require_attachment' => false])->assertOk();
        $this->actingAs($staff)->getJson('/api/v1/payments/lookups')->assertJsonCount(1, 'data.departments')->assertJsonPath('data.departments.0.name', 'Pharmacy');
        $this->getJson('/api/v1/payments/budget-lines?department_id='.$this->pharmacy->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/payments/budget-lines?department_id='.$lab->id)->assertForbidden();
        $body = ['department_id' => $this->pharmacy->id, 'title' => 'Outreach transport', 'lines' => [['budget_item_id' => $this->item, 'amount' => 40000]],
            'payees' => [['name' => 'Juma Staff', 'kind' => 'staff', 'method' => 'mobile', 'mobile_number' => '0713000000', 'amount' => 40000]]];
        $this->postJson('/api/v1/payments/requests', ['department_id' => $lab->id] + $body)->assertForbidden();
        $id = $this->postJson('/api/v1/payments/requests', $body)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payments/requests/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->getJson('/api/v1/payments/requests?mine=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.waiting_for', 'Step 1');
        $this->getJson("/api/v1/payments/requests/{$id}")->assertJsonPath('data.can.withdraw', true)->assertJsonPath('data.can.voucher', false);
        $this->getJson('/api/v1/payments/vouchers')->assertForbidden();
    }
}
