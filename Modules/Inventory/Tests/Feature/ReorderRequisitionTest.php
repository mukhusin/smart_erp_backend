<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Inventory\Actions\ReorderRequisitions;
use Modules\Inventory\Models\InventorySetting;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\ItemCategory;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\Store;
use Modules\Inventory\Models\Unit;
use Modules\Procurement\Models\PurchaseRequisition;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class ReorderRequisitionTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_items_below_reorder_level_get_one_draft_requisition_per_store(): void
    {
        $this->travelTo('2026-11-18 06:30:00');
        [$company] = $this->createCompany('Alpha', ['modules' => array_map(fn ($s) => ['slug' => $s], ['hr', 'admin', 'accounting', 'budgeting', 'inventory', 'procurement'])]);
        setPermissionsTeamId($company->id);
        $keeper = app(CompanyContext::class)->run($company, function () use ($company) {
            $keeper = User::factory()->create(['company_id' => $company->id]);
            $dept = Department::create(['name' => 'Stores', 'is_active' => true]);
            $year = app(FiscalCalendar::class)->createYear('2026-01-01');
            $inv = Account::create(['code' => '1400', 'name' => 'Stock', 'type' => 'asset', 'is_postable' => true, 'is_budgetable' => true, 'is_active' => true]);
            $other = Account::create(['code' => '1410', 'name' => 'Other stock', 'type' => 'asset', 'is_postable' => true, 'is_active' => true]);
            $budget = Budget::create(['fiscal_year_id' => $year->id, 'title' => 'Budget 2026', 'phasing' => 'quarter', 'status' => 'approved', 'created_by' => $keeper->id]);
            BudgetItem::create(['budget_id' => $budget->id, 'department_id' => $dept->id, 'account_id' => $inv->id, 'description' => 'Stationery', 'quantity' => 1, 'unit_cost' => 1000000,
                'original_amount' => 1000000, 'revised_amount' => 1000000]);
            $unit = Unit::create(['code' => 'RM', 'name' => 'Ream']);
            $cat = ItemCategory::create(['code' => 'STA', 'name' => 'Stationery', 'type' => 'store', 'inventory_account_id' => $inv->id]);
            $cat2 = ItemCategory::create(['code' => 'OTH', 'name' => 'Other', 'type' => 'store', 'inventory_account_id' => $other->id]);
            $paper = Item::create(['code' => 'STA-1', 'name' => 'A4 paper', 'item_category_id' => $cat->id, 'type' => 'store', 'use' => 'internal', 'unit_id' => $unit->id, 'reorder_level' => 20, 'max_level' => 100, 'last_cost' => 12000, 'is_active' => true]);
            $pens = Item::create(['code' => 'STA-2', 'name' => 'Pens', 'item_category_id' => $cat->id, 'type' => 'store', 'use' => 'internal', 'unit_id' => $unit->id, 'reorder_level' => 10, 'is_active' => true]);
            $odd = Item::create(['code' => 'OTH-1', 'name' => 'Odd item', 'item_category_id' => $cat2->id, 'type' => 'store', 'use' => 'internal', 'unit_id' => $unit->id, 'reorder_level' => 5, 'is_active' => true]);
            $store = Store::create(['code' => 'MAIN', 'name' => 'Main store', 'kind' => 'main', 'department_id' => $dept->id]);
            $store->users()->attach($keeper->id, ['role' => 'manager']);
            StockBalance::create(['store_id' => $store->id, 'item_id' => $paper->id, 'quantity' => 15, 'value' => 180000]);
            StockBalance::create(['store_id' => $store->id, 'item_id' => $pens->id, 'quantity' => 50, 'value' => 50000]);
            StockBalance::create(['store_id' => $store->id, 'item_id' => $odd->id, 'quantity' => 1, 'value' => 1000]);
            InventorySetting::current()->fill(['auto_requisition' => true])->save();

            return $keeper;
        });

        $this->assertSame(1, app(CompanyContext::class)->run($company, fn () => app(ReorderRequisitions::class)->run()));
        $r = app(CompanyContext::class)->run($company, fn () => PurchaseRequisition::with('lines')->first());
        $this->assertSame('draft', $r->status);
        $this->assertCount(1, $r->lines);
        $this->assertSame(85.0, (float) $r->lines[0]->quantity);
        $this->assertSame(1020000.0, (float) $r->total);
        $this->assertSame(1, $keeper->notifications()->where('data->title', "Draft requisition {$r->number}")->count());
        $this->assertStringContainsString('Odd item', $keeper->notifications()->first()->data['message']);
        $this->assertSame(0, app(CompanyContext::class)->run($company, fn () => app(ReorderRequisitions::class)->run()));
        $this->artisan('inventory:alerts')->assertSuccessful();
    }
}
