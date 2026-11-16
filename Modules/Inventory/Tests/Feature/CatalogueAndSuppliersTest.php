<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Accounting\Models\Account;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class CatalogueAndSuppliersTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_catalogue_stores_and_supplier_register(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-11-18 09:00:00');
        [$company] = $this->createCompany('Alpha', ['modules' => array_map(fn ($s) => ['slug' => $s], ['hr', 'admin', 'accounting', 'procurement', 'inventory'])]);
        setPermissionsTeamId($company->id);
        [$keeper, $buyer, $head, $acc] = app(CompanyContext::class)->run($company, function () use ($company) {
            Role::findOrCreate('keeper', 'web')->syncPermissions(['inventory.catalogue', 'inventory.stores']);
            Role::findOrCreate('buyer', 'web')->syncPermissions(['procurement.view', 'procurement.suppliers']);
            Role::findOrCreate('headproc', 'web')->syncPermissions(['procurement.manage']);
            $mk = fn ($r) => tap(User::factory()->create(['company_id' => $company->id]))->assignRole($r);
            $acc = ['inv' => Account::create(['code' => '1400', 'name' => 'Stock', 'type' => 'asset', 'is_postable' => true, 'is_active' => true])->id,
                'exp' => Account::create(['code' => '5600', 'name' => 'Office supplies', 'type' => 'expense', 'is_postable' => true, 'is_active' => true])->id];
            WorkflowTemplate::where('process', 'supplier-bank-account')->delete();
            WorkflowTemplate::create(['process' => 'supplier-bank-account', 'company_branch_id' => null, 'is_active' => true])
                ->steps()->create(['step_order' => 1, 'name' => 'Head of procurement', 'approver_type' => 'role', 'role_id' => Role::where('name', 'headproc')->value('id'), 'allow_send_back' => false]);

            return [$mk('keeper'), $mk('buyer'), $mk('headproc'), $acc];
        });

        $this->actingAs($keeper);
        $box = $this->postJson('/api/v1/inventory/units', ['code' => 'BOX', 'name' => 'Box'])->assertCreated()->json('data.id');
        $pcs = $this->postJson('/api/v1/inventory/units', ['code' => 'PCS', 'name' => 'Piece'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/inventory/units', ['code' => 'BOX', 'name' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $cat = $this->postJson('/api/v1/inventory/categories', ['code' => 'STA', 'name' => 'Stationery', 'type' => 'store', 'inventory_account_id' => $acc['inv'], 'expense_account_id' => $acc['exp']])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/inventory/categories', ['code' => 'ICT', 'name' => 'Computers', 'type' => 'asset', 'useful_life_months' => 48, 'residual_percent' => 10, 'capitalisation_threshold' => 500000])->assertCreated();
        $item = $this->postJson('/api/v1/inventory/items', ['name' => 'A4 paper', 'item_category_id' => $cat, 'use' => 'internal', 'unit_id' => $pcs, 'reorder_level' => 200,
            'units' => [['unit_id' => $box, 'factor' => 500]]])->assertCreated()->assertJsonPath('data.code', 'STA-0001')->json('data.id');
        $this->postJson('/api/v1/inventory/items', ['name' => 'Bad', 'item_category_id' => $cat, 'use' => 'internal', 'unit_id' => $pcs, 'units' => [['unit_id' => $pcs, 'factor' => 2]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['units.0.unit_id']);
        $csv = "code,name,category,unit,use,reorder_level\n,Pens blue,STA,PCS,internal,50\n,Unknown,XXX,PCS,internal,\n";
        $this->post('/api/v1/inventory/items/import', ['file' => UploadedFile::fake()->createWithContent('items.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.created', 1)->assertJsonCount(1, 'data.errors');
        $this->getJson('/api/v1/inventory/items?search=pen')->assertJsonPath('data.0.code', 'STA-0002');
        $this->getJson('/api/v1/inventory/categories')->assertJsonPath('data.1.items', 2)->assertJsonPath('data.1.accounts_text', 'Inventory: 1400 · Expense: 5600');

        $store = $this->postJson('/api/v1/inventory/stores', ['code' => 'HQ-MAIN', 'name' => 'Main store', 'kind' => 'main'])->assertCreated()->json('data.id');
        $shelf = $this->postJson("/api/v1/inventory/stores/{$store}/bins", ['code' => 'A', 'name' => 'Aisle A'])->assertCreated()->json('data.id');
        $bin = $this->postJson("/api/v1/inventory/stores/{$store}/bins", ['code' => 'A-1', 'name' => 'Shelf 1', 'parent_id' => $shelf])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/inventory/stores/{$store}/users", ['users' => [['user_id' => $keeper->id, 'role' => 'manager']]])->assertOk();
        $this->putJson("/api/v1/inventory/items/{$item}/levels", ['levels' => [['store_id' => $store, 'reorder_level' => 100, 'store_bin_id' => $bin]]])->assertOk();
        $this->getJson("/api/v1/inventory/items/{$item}")->assertJsonPath('data.units.0.factor', 500)->assertJsonPath('data.levels.0.bin', 'A-1');
        $this->getJson("/api/v1/inventory/stores/{$store}")->assertJsonPath('data.bins.1.parent', 'A')->assertJsonPath('data.users.0.role', 'manager');

        $this->actingAs($buyer);
        $sup = $this->postJson('/api/v1/procurement/suppliers', ['name' => 'Office World Ltd', 'kind' => 'company', 'tin' => '123-456-789', 'categories' => [$cat], 'special_group' => 'women'])
            ->assertCreated()->assertJsonPath('data.code', 'SUP-0001')->json('data.id');
        $this->postJson('/api/v1/procurement/suppliers', ['name' => 'Copy', 'kind' => 'company', 'tin' => '123-456-789'])->assertUnprocessable()->assertJsonValidationErrors(['tin']);
        $this->postJson("/api/v1/procurement/suppliers/{$sup}/bank-accounts", ['bank_name' => 'CRDB', 'account_name' => 'Office World Ltd', 'account_number' => '0150001'])
            ->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->getJson('/api/v1/procurement/suppliers')->assertJsonPath('data.0.has_bank', false);
        $wf = WorkflowInstance::where('process', 'supplier-bank-account')->value('id');
        $this->actingAs($head)->postJson("/api/v1/workflow/requests/{$wf}/approve")->assertOk();
        $this->actingAs($buyer)->getJson("/api/v1/procurement/suppliers/{$sup}")->assertJsonPath('data.bank_accounts.0.status', 'approved')->assertJsonPath('data.bank_accounts.0.is_default', true)
            ->assertJsonPath('data.category_names.0', 'Stationery');
        $this->post("/api/v1/procurement/suppliers/{$sup}/documents", ['type' => 'tax_clearance', 'expires_on' => '2026-12-01', 'file' => UploadedFile::fake()->create('tcc.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->getJson('/api/v1/procurement/suppliers?status=documents')->assertJsonCount(1, 'data')->assertJsonPath('data.0.expiring_documents', 1);
        $this->putJson("/api/v1/procurement/suppliers/{$sup}/blacklist", ['blacklisted' => true, 'reason' => 'Fraud'])->assertForbidden();
        $this->actingAs($head)->putJson("/api/v1/procurement/suppliers/{$sup}/blacklist", ['blacklisted' => true, 'reason' => 'Forged documents'])->assertOk()->assertJsonPath('data.blacklisted', true);
        $this->getJson('/api/v1/procurement/suppliers?status=blacklisted')->assertJsonCount(1, 'data');
    }
}
