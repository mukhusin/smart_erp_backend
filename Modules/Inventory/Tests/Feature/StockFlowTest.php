<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\ItemCategory;
use Modules\Inventory\Models\Store;
use Modules\Inventory\Models\Unit;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class StockFlowTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private array $u = [];

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-18 09:00:00');
        [$this->company] = $this->createCompany('Alpha', ['modules' => array_map(fn ($s) => ['slug' => $s], ['hr', 'admin', 'accounting', 'inventory'])]);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->run($this->company, function () {
            Role::findOrCreate('storehead', 'web')->syncPermissions(['inventory.manage', 'accounting.view']);
            foreach (['keeper' => 'employee', 'ward' => 'employee', 'boss' => 'director-general', 'storehead' => 'storehead'] as $k => $role) {
                $this->u[$k] = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole($role);
            }
            $dept = Department::create(['name' => 'Administration', 'is_active' => true]);
            $this->u['staff'] = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
            Employee::create(['employee_no' => 'S1', 'fname' => 'Asha', 'lname' => 'Staff', 'gender' => 'Female', 'department_id' => $dept->id, 'user_id' => $this->u['staff']->id,
                'employment_status' => 'confirmed', 'date_of_birth' => '1990-01-01']);
            app(FiscalCalendar::class)->createYear('2026-01-01');
            $acc = [];
            foreach ([['1400', 'Stock', 'asset'], ['3100', 'Opening balances', 'equity'], ['4500', 'Donations', 'revenue'], ['5600', 'Office supplies', 'expense']] as [$c, $n, $t]) {
                $acc[$c] = Account::create(['code' => $c, 'name' => $n, 'type' => $t, 'is_postable' => true, 'is_active' => true])->id;
            }
            $ream = Unit::create(['code' => 'REAM', 'name' => 'Ream']);
            $box = Unit::create(['code' => 'BOX', 'name' => 'Box']);
            $cat = ItemCategory::create(['code' => 'STA', 'name' => 'Stationery', 'type' => 'store', 'inventory_account_id' => $acc['1400'], 'expense_account_id' => $acc['5600']]);
            $paper = Item::create(['code' => 'STA-0001', 'name' => 'A4 paper', 'item_category_id' => $cat->id, 'type' => 'store', 'use' => 'internal', 'unit_id' => $ream->id, 'reorder_level' => 60]);
            $paper->units()->create(['unit_id' => $box->id, 'factor' => 5]);
            $main = Store::create(['code' => 'MAIN', 'name' => 'Main store', 'kind' => 'main']);
            $wardStore = Store::create(['code' => 'WARD', 'name' => 'Ward store', 'kind' => 'sub']);
            Store::create(['code' => 'TRANSIT', 'name' => 'Goods in transit', 'kind' => 'transit']);
            $main->users()->attach($this->u['keeper']->id, ['role' => 'manager']);
            $wardStore->users()->attach($this->u['ward']->id, ['role' => 'officer']);
            $this->ids = ['acc' => $acc, 'paper' => $paper->id, 'box' => $box->id, 'ream' => $ream->id, 'main' => $main->id, 'ward' => $wardStore->id, 'dept' => $dept->id];
            $boss = Role::where('name', 'director-general')->value('id');
            foreach (['store-requisition', 'stock-receipt', 'stock-adjustment'] as $p) {
                WorkflowTemplate::where('process', $p)->delete();
                WorkflowTemplate::create(['process' => $p, 'company_branch_id' => null, 'is_active' => true])
                    ->steps()->create(['step_order' => 1, 'name' => 'Director', 'approver_type' => 'role', 'role_id' => $boss, 'allow_send_back' => true]);
            }
            WorkflowTemplate::whereIn('process', ['stock-issue', 'stock-transfer'])->delete();
        });
    }

    private function approve(string $process): void
    {
        $id = WorkflowInstance::where('process', $process)->where('status', 'pending')->latest('id')->value('id');
        $this->actingAs($this->u['boss'])->postJson("/api/v1/workflow/requests/{$id}/approve")->assertOk();
    }

    private function receipt(string $reason, string $date, float $qty, float $cost): void
    {
        $id = $this->actingAs($this->u['keeper'])->postJson('/api/v1/inventory/documents', ['kind' => 'receipt', 'reason' => $reason, 'store_id' => $this->ids['main'], 'date' => $date,
            'lines' => [['item_id' => $this->ids['paper'], 'quantity' => $qty, 'unit_cost' => $cost]]])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/inventory/documents/{$id}/submit")->assertOk();
        $this->approve('stock-receipt');
    }

    private function card(int $store): array
    {
        return $this->actingAs($this->u['storehead'])->getJson("/api/v1/inventory/stock/items/{$this->ids['paper']}?store_id={$store}")->assertOk()->json();
    }

    public function test_receipts_requests_issues_transfers_counts_and_reversal_at_weighted_average(): void
    {
        $this->actingAs($this->u['storehead'])->putJson('/api/v1/inventory/stock-settings', ['opening_account_id' => $this->ids['acc']['3100'], 'donation_account_id' => $this->ids['acc']['4500'],
            'expiry_warning_days' => 90, 'slow_moving_days' => 180])->assertOk()->assertJsonPath('data.costing', 'Weighted average');
        $this->receipt('opening', '2026-11-01', 50, 1000);
        $this->receipt('donation', '2026-11-05', 50, 1400);
        $this->assertSame([100, 120000], [$this->card($this->ids['main'])['balance']['quantity'], $this->card($this->ids['main'])['balance']['value']]);

        $req = $this->actingAs($this->u['staff'])->postJson('/api/v1/inventory/requests', ['store_id' => $this->ids['main'], 'department_id' => $this->ids['dept'], 'purpose' => 'Office',
            'lines' => [['item_id' => $this->ids['paper'], 'unit_id' => $this->ids['ream'], 'quantity' => 30]]])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/inventory/requests/{$req}/submit")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->approve('store-requisition');
        $line = $this->actingAs($this->u['keeper'])->getJson("/api/v1/inventory/requests/{$req}")->assertJsonPath('data.can.issue', true)->assertJsonPath('data.lines.0.available', 100)->json('data.lines.0.id');

        $big = $this->postJson('/api/v1/inventory/documents', ['kind' => 'issue', 'store_id' => $this->ids['main'], 'department_id' => $this->ids['dept'], 'date' => '2026-11-18',
            'lines' => [['item_id' => $this->ids['paper'], 'quantity' => 1000]]])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/inventory/documents/{$big}/submit")->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
        $iss = $this->postJson('/api/v1/inventory/documents', ['kind' => 'issue', 'store_id' => $this->ids['main'], 'store_requisition_id' => $req, 'date' => '2026-11-18',
            'lines' => [['item_id' => $this->ids['paper'], 'unit_id' => $this->ids['box'], 'quantity' => 6, 'store_requisition_line_id' => $line]]])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/inventory/documents/{$iss}/submit")->assertOk()->assertJsonPath('data.status', 'awaiting');
        $this->getJson("/api/v1/inventory/documents/{$iss}")->assertJsonPath('data.value', 36000)->assertJsonPath('data.lines.0.base_quantity', 30);
        $this->actingAs($this->u['staff'])->getJson("/api/v1/inventory/requests/{$req}")->assertJsonPath('data.status', 'issued')->assertJsonPath('data.issues.0.can_confirm', true);
        $this->postJson("/api/v1/inventory/documents/{$iss}/confirm")->assertOk()->assertJsonPath('data.status', 'received');
        $this->assertSame([70, 84000], [$this->card($this->ids['main'])['balance']['quantity'], $this->card($this->ids['main'])['balance']['value']]);

        $trf = $this->actingAs($this->u['keeper'])->postJson('/api/v1/inventory/documents', ['kind' => 'transfer', 'store_id' => $this->ids['main'], 'to_store_id' => $this->ids['ward'], 'date' => '2026-11-18',
            'lines' => [['item_id' => $this->ids['paper'], 'quantity' => 20]]])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/inventory/documents/{$trf}/submit")->assertOk()->assertJsonPath('data.status', 'transit');
        $tl = $this->actingAs($this->u['ward'])->getJson("/api/v1/inventory/documents/{$trf}")->assertJsonPath('data.can.receive', true)->json('data.lines.0.id');
        $this->postJson("/api/v1/inventory/documents/{$trf}/receive", ['lines' => [['id' => $tl, 'received' => 18]]])->assertUnprocessable();
        $this->postJson("/api/v1/inventory/documents/{$trf}/receive", ['lines' => [['id' => $tl, 'received' => 18, 'reason' => '2 reams wet']]])->assertOk()->assertJsonPath('data.status', 'received');
        $this->assertSame(18, $this->card($this->ids['ward'])['balance']['quantity']);

        $cnt = $this->actingAs($this->u['keeper'])->postJson('/api/v1/inventory/documents', ['kind' => 'count', 'store_id' => $this->ids['main'], 'date' => '2026-11-18'])->assertCreated()->json('data.id');
        $cl = $this->getJson("/api/v1/inventory/documents/{$cnt}")->assertJsonPath('data.lines.0.system_quantity', 50)->json('data.lines.0.id');
        $this->putJson("/api/v1/inventory/documents/{$cnt}/counts", ['counts' => [['id' => $cl, 'counted' => 48]]])->assertOk();
        $this->postJson("/api/v1/inventory/documents/{$cnt}/submit")->assertUnprocessable()->assertJsonValidationErrors(['lines']);
        $this->putJson("/api/v1/inventory/documents/{$cnt}/counts", ['counts' => [['id' => $cl, 'counted' => 48, 'reason' => 'Damaged by water']]])->assertOk();
        $this->postJson("/api/v1/inventory/documents/{$cnt}/submit")->assertOk();
        $this->approve('stock-adjustment');
        $this->assertSame([48, 57600], [$this->card($this->ids['main'])['balance']['quantity'], $this->card($this->ids['main'])['balance']['value']]);

        $this->actingAs($this->u['storehead'])->postJson("/api/v1/inventory/documents/{$iss}/reverse", ['reason' => 'Issued to the wrong department'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $card = $this->card($this->ids['main']);
        $this->assertSame([78, 93600], [$card['balance']['quantity'], $card['balance']['value']]);
        $this->assertSame('reversal', $card['movements'][0]['type']);
        $this->getJson('/api/v1/inventory/stock?filter=low')->assertJsonCount(1, 'data')->assertJsonPath('data.0.store', 'Ward store');
        $tb = collect($this->getJson('/api/v1/reports/finance/trial-balance')->json('data.rows'))->keyBy('code');
        $this->assertSame(93600 + 18 * 1200 + 2 * 1200, $tb['1400']['debit']);
        $this->artisan('inventory:alerts')->assertSuccessful();
        foreach (['stock-balance', 'stock-movements', 'stock-consumption', 'stock-expiry', 'stock-reorder', 'stock-slow', 'stock-transit', 'stock-count-variance'] as $r) {
            $this->actingAs($this->u['storehead'])->getJson("/api/v1/reports/finance/{$r}")->assertOk();
        }
        $this->getJson('/api/v1/reports/finance/stock-transit')->assertJsonPath('data.rows.0.quantity', 2);
        $this->getJson('/api/v1/reports/finance/stock-count-variance')->assertJsonPath('data.rows.0.variance', -2);
    }

    public function test_fifo_costs_issues_from_the_oldest_layer(): void
    {
        $this->actingAs($this->u['storehead'])->putJson('/api/v1/inventory/stock-settings', ['opening_account_id' => $this->ids['acc']['3100'], 'donation_account_id' => $this->ids['acc']['4500'],
            'expiry_warning_days' => 90, 'slow_moving_days' => 180])->assertOk();
        $this->putJson('/api/v1/accounting/framework', ['framework' => 'ifrs_sme', 'policies' => ['inventory_costing' => 'fifo']])->assertForbidden();
        app(CompanyContext::class)->run($this->company, fn () => app(\Modules\Accounting\Services\AccountingPolicies::class)->change(['framework' => 'ifrs_sme', 'policies' => ['inventory_costing' => 'fifo']], $this->u['storehead']));
        $this->receipt('opening', '2026-11-01', 50, 1000);
        $this->receipt('donation', '2026-11-05', 50, 1400);
        $iss = $this->actingAs($this->u['keeper'])->postJson('/api/v1/inventory/documents', ['kind' => 'issue', 'store_id' => $this->ids['main'], 'department_id' => $this->ids['dept'], 'date' => '2026-11-18',
            'lines' => [['item_id' => $this->ids['paper'], 'quantity' => 60]]])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/inventory/documents/{$iss}/submit")->assertOk()->assertJsonPath('data.status', 'posted');
        $this->getJson("/api/v1/inventory/documents/{$iss}")->assertJsonPath('data.value', 64000);
        $this->assertSame([40, 56000], [$this->card($this->ids['main'])['balance']['quantity'], $this->card($this->ids['main'])['balance']['value']]);
    }
}
