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
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Accounting\Services\PostingService;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Services\BudgetService;
use Modules\Payments\Actions\ManageRetirements;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class PettyCashTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $finance;

    private User $staff;

    private int $staffEmployee;

    private Department $dept;

    private array $acc = [];

    private int $bank;

    private int $item;

    private int $cash;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-11-18 09:00:00');
        [$this->company] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'accounting'], ['slug' => 'budgeting'], ['slug' => 'payments']]]);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->run($this->company, function () {
            Role::findOrCreate('accountant', 'web')->syncPermissions(['accounting.view', 'accounting.journals', 'accounting.manage', 'budgeting.view', 'budgeting.manage',
                'payments.view', 'payments.vouchers', 'payments.pay', 'payments.manage']);
            $this->finance = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('accountant');
            $this->dept = Department::create(['name' => 'Outreach', 'is_active' => true]);
            $this->staff = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
            $this->staffEmployee = Employee::create(['employee_no' => 'OR1', 'fname' => 'Asha', 'lname' => 'Field', 'gender' => 'Female', 'department_id' => $this->dept->id,
                'user_id' => $this->staff->id, 'employment_status' => 'confirmed', 'date_of_birth' => '1990-11-15'])->id;
            $year = app(FiscalCalendar::class)->createYear('2026-01-01');
            foreach ([['1100', 'CRDB', 'asset'], ['1300', 'Staff advances', 'asset'], ['2100', 'Payables', 'liability'], ['3000', 'Fund balance', 'equity'], ['5200', 'Travel', 'expense']] as [$code, $name, $type]) {
                $this->acc[$code] = Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'is_postable' => true, 'is_budgetable' => $type === 'expense', 'is_active' => true])->id;
            }
            $this->bank = CashBankAccount::create(['name' => 'CRDB main', 'kind' => 'bank', 'account_id' => $this->acc['1100'], 'currency' => 'TZS', 'is_active' => true])->id;
            $this->acc['1050'] = Account::create(['code' => '1050', 'name' => 'Petty cash', 'type' => 'asset', 'is_postable' => true, 'is_active' => true])->id;
            $this->cash = CashBankAccount::create(['name' => 'Petty cash office', 'kind' => 'cash', 'account_id' => $this->acc['1050'], 'currency' => 'TZS', 'is_active' => true])->id;
            Role::findOrCreate('cashier-petty', 'web')->syncPermissions(['payments.petty-cash', 'self-service.use']);
            $this->staff->assignRole('cashier-petty');
            app(PostingService::class)->post('2026-01-01', 'Opening', [['account_id' => $this->acc['1100'], 'debit' => 1000000], ['account_id' => $this->acc['3000'], 'credit' => 1000000]], $this->finance, 'opening');
            AccountingSetting::current()->fill(['budget_release' => 'none', 'budget_control' => 'warn'])->save();
            $budget = Budget::create(['fiscal_year_id' => $year->id, 'title' => 'Budget 2026', 'phasing' => 'quarter', 'status' => 'approved', 'created_by' => $this->finance->id]);
            $this->item = BudgetItem::create(['budget_id' => $budget->id, 'department_id' => $this->dept->id, 'account_id' => $this->acc['5200'], 'description' => 'Field travel',
                'quantity' => 1, 'unit_cost' => 500000, 'original_amount' => 500000, 'revised_amount' => 500000])->id;
            app(BudgetService::class)->record($this->item, 'approved', 500000, $budget, 'Approved');
            WorkflowTemplate::whereIn('process', ['payment-request', 'payment-voucher', 'payment-list'])->delete();
            $this->assertTrue(WorkflowTemplate::where('process', 'petty-cash-voucher')->exists());
            WorkflowTemplate::whereIn('process', ['petty-cash-voucher', 'petty-cash-replenishment'])->delete();
            $t = WorkflowTemplate::create(['process' => 'petty-cash-replenishment', 'company_branch_id' => null, 'is_active' => true]);
            $t->steps()->create(['step_order' => 1, 'name' => 'Finance', 'approver_type' => 'role', 'role_id' => Role::where('name', 'accountant')->value('id'), 'allow_send_back' => true]);
        });
        $this->giveActiveContracts($this->company);
    }

    private function figures(): array
    {
        return $this->actingAs($this->finance)->getJson("/api/v1/budgeting/items/{$this->item}")->json('data');
    }

    private function balance(string $code): float
    {
        return app(CompanyContext::class)->run($this->company, fn () => round((float) \DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('e.status', ['posted', 'reversed'])->where('l.account_id', $this->acc[$code])->selectRaw('SUM(l.debit) - SUM(l.credit) as b')->value('b'), 2));
    }

    public function test_float_vouchers_and_top_up(): void
    {
        $this->actingAs($this->finance)->postJson('/api/v1/payments/petty-cash', ['cash_bank_account_id' => $this->bank, 'custodian_id' => $this->staff->id, 'float_amount' => 100000])
            ->assertUnprocessable()->assertJsonValidationErrors(['cash_bank_account_id']);
        $float = $this->postJson('/api/v1/payments/petty-cash', ['cash_bank_account_id' => $this->cash, 'custodian_id' => $this->staff->id, 'float_amount' => 100000,
            'max_voucher' => 30000, 'replenish_from_id' => $this->bank])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/payments/petty-cash/users?search='.$this->staff->username)->assertOk()->assertJsonPath('data.0.id', $this->staff->id);

        $this->actingAs($this->staff)->getJson('/api/v1/payments/petty-cash')->assertOk()->assertJsonPath('data.0.balance', 0)->assertJsonPath('data.0.mine', true);
        $this->postJson("/api/v1/payments/petty-cash/{$float}/top-up", ['date' => '2026-11-18'])->assertCreated()->assertJsonPath('data.status', 'pending');
        $wf = WorkflowInstance::where('process', 'petty-cash-replenishment')->where('status', 'pending')->value('id');
        $this->actingAs($this->finance)->postJson("/api/v1/workflow/requests/{$wf}/approve")->assertOk();
        $this->assertSame(100000.0, $this->balance('1050'));
        $this->assertSame(900000.0, $this->balance('1100'));

        $receipt = UploadedFile::fake()->create('receipt.jpg', 20, 'image/jpeg');
        $voucher = fn (float $amount) => $this->actingAs($this->staff)->post("/api/v1/payments/petty-cash/{$float}/vouchers", ['date' => '2026-11-18', 'payee' => 'Duka la Mama',
            'description' => 'Fuel for generator', 'budget_item_id' => $this->item, 'amount' => $amount, 'receipt' => $receipt], ['Accept' => 'application/json']);
        $voucher(40000)->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $voucher(25000)->assertCreated()->assertJsonPath('data.status', 'approved');
        $voucher(30000)->assertCreated();
        $this->assertSame(45000.0, $this->balance('1050'));
        $this->assertSame(55000.0, $this->balance('5200'));
        $this->assertSame(55000.0, (float) $this->figures()['actual']);
        $this->assertSame(0.0, (float) $this->figures()['reserved']);

        $show = $this->actingAs($this->staff)->getJson("/api/v1/payments/petty-cash/{$float}")->assertOk()->json('data');
        $this->assertSame(55000.0, (float) $show['float']['spent_since_top_up']);
        $this->assertCount(2, $show['vouchers']);
        $this->get("/api/v1/payments/petty-cash-vouchers/{$show['vouchers'][0]['id']}/receipt")->assertOk();

        $this->postJson("/api/v1/payments/petty-cash/{$float}/top-up", ['date' => '2026-11-18', 'amount' => 60000])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->postJson("/api/v1/payments/petty-cash/{$float}/top-up", ['date' => '2026-11-18'])->assertCreated();
        $wf = WorkflowInstance::where('process', 'petty-cash-replenishment')->where('status', 'pending')->value('id');
        $this->actingAs($this->finance)->postJson("/api/v1/workflow/requests/{$wf}/approve")->assertOk();
        $this->assertSame(100000.0, $this->balance('1050'));
        $show = $this->actingAs($this->staff)->getJson("/api/v1/payments/petty-cash/{$float}")->json('data');
        $this->assertSame(0.0, (float) $show['float']['spent_since_top_up']);
        $this->assertTrue($show['vouchers'][0]['topped_up']);

        $other = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
        $this->actingAs($other)->getJson("/api/v1/payments/petty-cash/{$float}")->assertForbidden();
    }
}
