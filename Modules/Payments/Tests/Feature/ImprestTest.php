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

class ImprestTest extends TestCase
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
            app(PostingService::class)->post('2026-01-01', 'Opening', [['account_id' => $this->acc['1100'], 'debit' => 1000000], ['account_id' => $this->acc['3000'], 'credit' => 1000000]], $this->finance, 'opening');
            AccountingSetting::current()->fill(['budget_release' => 'none', 'budget_control' => 'warn'])->save();
            $budget = Budget::create(['fiscal_year_id' => $year->id, 'title' => 'Budget 2026', 'phasing' => 'quarter', 'status' => 'approved', 'created_by' => $this->finance->id]);
            $this->item = BudgetItem::create(['budget_id' => $budget->id, 'department_id' => $this->dept->id, 'account_id' => $this->acc['5200'], 'description' => 'Field travel',
                'quantity' => 1, 'unit_cost' => 500000, 'original_amount' => 500000, 'revised_amount' => 500000])->id;
            app(BudgetService::class)->record($this->item, 'approved', 500000, $budget, 'Approved');
            WorkflowTemplate::whereIn('process', ['payment-request', 'payment-voucher', 'payment-list'])->delete();
            $this->assertTrue(WorkflowTemplate::where('process', 'imprest-retirement')->exists());
            WorkflowTemplate::where('process', 'imprest-retirement')->delete();
            $t = WorkflowTemplate::create(['process' => 'imprest-retirement', 'company_branch_id' => null, 'is_active' => true]);
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

    private function imprest(array $extra = []): int
    {
        $body = array_merge(['kind' => 'imprest', 'holder_employee_id' => $this->staffEmployee, 'department_id' => $this->dept->id, 'title' => 'Field visit Kibaha', 'needed_by' => '2026-11-20',
            'retire_by' => '2026-11-27', 'lines' => [['budget_item_id' => $this->item, 'amount' => 200000]], 'payees' => [['method' => 'mobile', 'mobile_number' => '0712000000']]], $extra);
        $id = $this->actingAs($this->staff)->postJson('/api/v1/payments/requests', $body)->assertCreated()->json('data.id');
        $this->post("/api/v1/payments/requests/{$id}/attachments", ['file' => UploadedFile::fake()->create('memo.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();

        return $id;
    }

    private function payOut(int $id): void
    {
        $this->actingAs($this->finance)->postJson("/api/v1/payments/requests/{$id}/voucher", ['cash_bank_account_id' => $this->bank, 'date' => '2026-11-18'])->assertCreated();
        $voucher = $this->getJson("/api/v1/payments/requests/{$id}")->json('data.vouchers.0.id');
        $this->postJson('/api/v1/payments/lists', ['cash_bank_account_id' => $this->bank, 'method' => 'mobile', 'date' => '2026-11-18', 'voucher_ids' => [$voucher]])->assertCreated();
    }

    public function test_imprest_is_advanced_then_retired_into_expense(): void
    {
        $this->actingAs($this->staff)->getJson('/api/v1/payments/lookups')->assertJsonPath('data.employee.id', $this->staffEmployee);
        $this->getJson('/api/v1/payments/staff?department_id='.$this->dept->id)->assertOk()->assertJsonCount(1, 'data');

        $this->postJson('/api/v1/payments/requests', ['kind' => 'imprest', 'holder_employee_id' => $this->staffEmployee, 'department_id' => $this->dept->id, 'title' => 'x', 'needed_by' => '2026-11-20',
            'retire_by' => '2026-12-30', 'lines' => [['budget_item_id' => $this->item, 'amount' => 1000]], 'payees' => [['method' => 'cash']]])->assertUnprocessable()->assertJsonValidationErrors(['retire_by']);

        $id = $this->imprest();
        $this->getJson("/api/v1/payments/requests/{$id}")->assertJsonPath('data.kind', 'imprest')->assertJsonPath('data.payees.0.name', 'Asha Field')
            ->assertJsonPath('data.payees.0.kind', 'staff')->assertJsonPath('data.payees.0.amount', 200000);
        $this->postJson("/api/v1/payments/requests/{$id}/submit")->assertUnprocessable()->assertJsonValidationErrors(['request' => 'staff advances']);
        $this->actingAs($this->finance)->putJson('/api/v1/payments/settings', ['payable_account_id' => $this->acc['2100'], 'imprest_account_id' => $this->acc['1300'], 'require_attachment' => true])->assertOk()
            ->assertJsonPath('data.imprest_one_open', true)->assertJsonPath('data.imprest_retire_days', 14);
        $this->actingAs($this->staff)->postJson("/api/v1/payments/requests/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'approved');

        $second = $this->imprest(['title' => 'Second trip']);
        $this->postJson("/api/v1/payments/requests/{$second}/submit")->assertUnprocessable()->assertJsonValidationErrors(['request' => 'still holds']);

        $this->payOut($id);
        $this->assertSame(200000.0, $this->balance('1300'));
        $this->assertSame(0.0, $this->balance('5200'));
        $this->assertSame(200000.0, (float) $this->figures()['committed']);
        $this->assertSame(0.0, (float) $this->figures()['actual']);
        $this->actingAs($this->staff)->getJson("/api/v1/payments/requests/{$id}")->assertJsonPath('data.status', 'paid')->assertJsonPath('data.can.retire', true);

        $ret = $this->postJson("/api/v1/payments/requests/{$id}/retirements", ['date' => '2026-11-25', 'lines' => [['budget_item_id' => $this->item, 'amount' => 170000]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['cash_bank_account_id']);
        $this->postJson("/api/v1/payments/requests/{$id}/retirements", ['date' => '2026-11-25', 'lines' => [['budget_item_id' => $this->item, 'amount' => 250000]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['lines']);
        $ret = $this->postJson("/api/v1/payments/requests/{$id}/retirements", ['date' => '2026-11-25', 'lines' => [['budget_item_id' => $this->item, 'description' => 'Fuel and allowances', 'amount' => 170000]],
            'cash_bank_account_id' => $this->bank, 'return_reference' => 'DEP-77'])->assertCreated()->assertJsonPath('data.number', 'IR-2026-00001')->json('data.id');
        $this->postJson("/api/v1/payments/retirements/{$ret}/submit")->assertUnprocessable()->assertJsonValidationErrors(['attachments']);
        $this->post("/api/v1/payments/retirements/{$ret}/attachments", ['file' => UploadedFile::fake()->create('receipts.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->getJson("/api/v1/payments/requests/{$id}")->assertJsonCount(1, 'data.attachments');
        $this->getJson("/api/v1/payments/retirements/{$ret}")->assertJsonPath('data.returned', 30000)->assertJsonCount(1, 'data.attachments');
        $this->postJson("/api/v1/payments/retirements/{$ret}/submit")->assertOk()->assertJsonPath('data.status', 'pending');

        $wf = WorkflowInstance::where('process', 'imprest-retirement')->where('status', 'pending')->value('id');
        $this->actingAs($this->finance)->postJson("/api/v1/workflow/requests/{$wf}/approve")->assertOk();

        $this->getJson("/api/v1/payments/retirements/{$ret}")->assertJsonPath('data.status', 'approved');
        $this->getJson("/api/v1/payments/requests/{$id}")->assertJsonPath('data.status', 'retired');
        $this->assertSame(0.0, $this->balance('1300'));
        $this->assertSame(170000.0, $this->balance('5200'));
        $this->assertSame(1000000.0 - 200000 + 30000, $this->balance('1100'));
        $f = $this->figures();
        $this->assertSame(170000.0, (float) $f['actual']);
        $this->assertSame(0.0, (float) $f['committed']);
        $this->assertSame(330000.0, (float) $f['available']);
        $this->assertSame('imprest', app(CompanyContext::class)->run($this->company, fn () => JournalEntry::latest('id')->value('entry_type')));

        $this->actingAs($this->staff)->postJson("/api/v1/payments/requests/{$second}/submit")->assertOk();
        $this->payOut($second);
        $this->getJson('/api/v1/reports/finance/imprest-outstanding')->assertOk()->assertJsonPath('data.rows.0.number', 'PR-2026-00002');
    }

    public function test_overdue_imprest_reminds_the_holder(): void
    {
        $this->actingAs($this->finance)->putJson('/api/v1/payments/settings', ['payable_account_id' => $this->acc['2100'], 'imprest_account_id' => $this->acc['1300'], 'require_attachment' => true])->assertOk();
        $id = $this->imprest();
        $this->postJson("/api/v1/payments/requests/{$id}/submit")->assertOk();
        $this->payOut($id);
        $this->travelTo('2026-11-28 07:00:00');
        $this->assertSame(1, app(CompanyContext::class)->run($this->company, fn () => app(ManageRetirements::class)->remind()));
        $this->assertSame(1, $this->staff->notifications()->where('data->title', 'Imprest not retired')->count());
        $this->actingAs($this->finance)->getJson('/api/v1/payments/requests?overdue=1')->assertJsonPath('meta.total', 1);
        $this->travelTo('2026-11-29 07:00:00');
        $this->assertSame(0, app(CompanyContext::class)->run($this->company, fn () => app(ManageRetirements::class)->remind()));
    }

    public function test_unretired_imprest_is_recovered_from_salary(): void
    {
        $this->actingAs($this->finance)->putJson('/api/v1/payments/settings', ['payable_account_id' => $this->acc['2100'], 'imprest_account_id' => $this->acc['1300'], 'require_attachment' => true])->assertOk();
        $id = $this->imprest();
        $this->postJson("/api/v1/payments/requests/{$id}/submit")->assertOk();
        $this->payOut($id);
        app(CompanyContext::class)->run($this->company, function () {
            \Modules\Accounting\Models\PayrollPostingSetting::current()->forceFill(['other_account_id' => $this->acc['2100']])->save();
            \Modules\Workflow\Models\WorkflowTemplate::where('process', 'other-deduction')->delete();
            app(FiscalCalendar::class)->createYear('2027-01-01');
        });
        $this->actingAs($this->staff)->postJson("/api/v1/payments/requests/{$id}/recover", ['months' => 3, 'start_month' => '2026-11'])->assertForbidden();
        $this->actingAs($this->finance)->getJson("/api/v1/payments/requests/{$id}")->assertJsonPath('data.can.recover', true);
        $this->postJson("/api/v1/payments/requests/{$id}/recover", ['months' => 3, 'start_month' => '2026-11', 'reason' => 'Not retired after reminders'])->assertCreated();
        $this->getJson("/api/v1/payments/requests/{$id}")->assertJsonPath('data.status', 'recovering')->assertJsonPath('data.recoveries.0.months', 3)
            ->assertJsonPath('data.can.retire', false);
        $amounts = app(CompanyContext::class)->run($this->company, fn () => \App\Models\OtherDeduction::where('employee_id', $this->staffEmployee)->orderBy('start_month')->get(['amount', 'months', 'status'])->toArray());
        $this->assertSame([[66666.66, 2, 'approved'], [66666.68, 1, 'approved']], array_map(fn ($d) => [(float) $d['amount'], $d['months'], $d['status']], $amounts));

        $pay = function (int $month) {
            app(CompanyContext::class)->run($this->company, function () use ($month) {
                $start = \Illuminate\Support\Carbon::create(2026, $month, 1);
                $sheet = \App\Models\PayrollSheet::create(['name' => "Payroll {$month}", 'year' => (string) $start->year, 'month' => (string) $start->month,
                    'payroll_from_date' => $start->toDateString(), 'payroll_to_date' => $start->copy()->endOfMonth()->toDateString(), 'status' => 'approved']);
                \App\Models\PayrollSheetDetail::create(['payroll_sheet_id' => $sheet->id, 'employee_id' => $this->staffEmployee, 'full_name' => 'Asha Field', 'basic_salary' => 900000, 'gross_salary' => 900000, 'net_salary' => 800000]);
                event(new \Modules\Hr\Events\PayrollApproved($sheet, $this->finance));
            });
        };
        $pay(11);
        $this->assertSame(133333.34, $this->balance('1300'));
        $pay(12);
        $pay(13);
        $this->assertSame(0.0, $this->balance('1300'));
        $this->getJson("/api/v1/payments/requests/{$id}")->assertJsonPath('data.status', 'recovered')->assertJsonPath('data.recoveries.0.recovered', 200000);
        $this->assertSame(0.0, (float) $this->figures()['committed']);
        $this->assertSame(0.0, (float) $this->figures()['actual']);
    }
}
