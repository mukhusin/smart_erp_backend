<?php

namespace Modules\Assets\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Assets\Models\Asset;
use Modules\Inventory\Models\ItemCategory;
use Modules\Reporting\Support\FinanceReports;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class AssetLifecycleTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_register_depreciate_change_transfer_and_dispose(): void
    {
        $this->travelTo('2026-01-10 09:00:00');
        [$company] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'accounting'], ['slug' => 'inventory'], ['slug' => 'procurement'], ['slug' => 'assets']]]);
        setPermissionsTeamId($company->id);
        [$officer, $staff, $a, $ids] = app(CompanyContext::class)->run($company, function () use ($company) {
            Role::findOrCreate('officer', 'web')->syncPermissions(['assets.view', 'assets.manage', 'accounting.view', 'reports.view']);
            Role::findOrCreate('staff', 'web')->syncPermissions([]);
            $officer = tap(User::factory()->create(['company_id' => $company->id]))->assignRole('officer');
            $staff = tap(User::factory()->create(['company_id' => $company->id]))->assignRole('staff');
            $admin = Department::create(['name' => 'Administration', 'is_active' => true]);
            $lab = Department::create(['name' => 'Laboratory', 'is_active' => true]);
            $emp = Employee::create(['employee_no' => 'E1', 'fname' => 'Asha', 'lname' => 'M', 'gender' => 'Female', 'department_id' => $lab->id, 'user_id' => $staff->id,
                'employment_status' => 'confirmed', 'date_of_birth' => '1990-01-01']);
            app(FiscalCalendar::class)->createYear('2026-01-01');
            $mk = fn ($code, $name, $type) => Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'is_postable' => true, 'is_active' => true])->id;
            $a = ['bank' => $mk('1100', 'Bank', 'asset'), 'cost' => $mk('1500', 'Motor vehicles', 'asset'), 'accum' => $mk('1590', 'Accumulated depreciation', 'asset'),
                'opening' => $mk('3200', 'Opening balances', 'equity'), 'reval' => $mk('3300', 'Revaluation surplus', 'equity'), 'donation' => $mk('4500', 'Donated assets', 'revenue'),
                'dep' => $mk('6100', 'Depreciation', 'expense'), 'impair' => $mk('6200', 'Impairment loss', 'expense'), 'gain' => $mk('6900', 'Gain or loss on disposal', 'expense'),
                'repairs' => $mk('2100', 'Payables', 'liability')];
            $cat = ItemCategory::create(['code' => 'VEH', 'name' => 'Motor vehicles', 'type' => 'asset', 'asset_account_id' => $a['cost'], 'depreciation_account_id' => $a['dep'],
                'accumulated_account_id' => $a['accum'], 'useful_life_months' => 60, 'residual_percent' => 10]);
            CashBankAccount::create(['name' => 'CRDB', 'kind' => 'bank', 'account_id' => $a['bank'], 'is_active' => true]);
            $this->assertSame(4, WorkflowTemplate::whereIn('process', ['asset-register', 'asset-change', 'asset-transfer', 'asset-disposal'])->count());
            WorkflowTemplate::whereIn('process', ['asset-register', 'asset-change', 'asset-transfer', 'asset-disposal'])->delete();

            return [$officer, $staff, $a, ['cat' => $cat->id, 'admin' => $admin->id, 'lab' => $lab->id, 'emp' => $emp->id]];
        });
        $this->actingAs($officer);

        $car = $this->postJson('/api/v1/assets/register', ['item_category_id' => $ids['cat'], 'name' => 'Land Cruiser', 'acquisition_type' => 'opening', 'acquisition_date' => '2025-01-01',
            'in_use_date' => '2025-01-01', 'cost' => 1200000, 'opening_depreciation' => 240000, 'asset_class' => 'vehicle', 'details' => ['registration' => 'T 123 ABC'],
            'department_id' => $ids['admin']])->assertOk()->json('data.0');
        $this->assertSame('VEH-2025-0001', $car['number']);
        $this->postJson("/api/v1/assets/register/{$car['id']}/submit")->assertUnprocessable();
        $this->putJson('/api/v1/assets/settings', ['number_pattern' => '{CAT}-{YEAR}-{SEQ}', 'depreciation_start' => 'in_use', 'opening_account_id' => $a['opening'],
            'donation_account_id' => $a['donation'], 'gain_loss_account_id' => $a['gain'], 'revaluation_account_id' => $a['reval'], 'impairment_account_id' => $a['impair'], 'confirmation_month' => 1])->assertOk();
        $this->postJson("/api/v1/assets/register/{$car['id']}/submit")->assertOk()->assertJsonPath('data.status', 'active');
        $show = $this->getJson("/api/v1/assets/register/{$car['id']}")->assertOk()->json('data');
        $this->assertEquals(960000, $show['value']['book_value']);
        $this->assertSame('2029-12-31', $show['depreciation']['until']);
        $this->assertSame('T 123 ABC', $show['details']['registration']);

        $bikes = $this->postJson('/api/v1/assets/register', ['item_category_id' => $ids['cat'], 'name' => 'Motorbike', 'acquisition_type' => 'donation', 'acquisition_date' => '2026-01-05',
            'cost' => 300000, 'quantity' => 2, 'serials' => ['MB-1', 'MB-2']])->json('data');
        $this->assertCount(2, $bikes);
        $this->assertSame(1, $this->postJson('/api/v1/assets/register/submit-drafts')->json('data.submitted') > 0 ? 1 : 0);
        app(CompanyContext::class)->run($company, fn () => $this->assertSame(['registered', 'registered'], Asset::whereIn('id', array_column($bikes, 'id'))->pluck('status')->all()));

        $this->travelTo('2026-02-05 09:00:00');
        $this->postJson('/api/v1/assets/depreciation/run', ['period_end' => '2026-03-31'])->assertUnprocessable();
        $run = $this->postJson('/api/v1/assets/depreciation/run', ['period_end' => '2026-01-31'])->assertOk()->json('data');
        $this->assertSame(1, $run['assets']);
        $this->assertEquals(round(840000 / 1461 * 31, 2), $run['total']);
        $this->postJson('/api/v1/assets/depreciation/run', ['period_end' => '2026-01-31'])->assertUnprocessable();
        $this->assertSame('2026-02-28', $this->getJson('/api/v1/assets/depreciation')->json('next_period'));

        $this->postJson("/api/v1/assets/register/{$car['id']}/changes", ['kind' => 'improvement', 'date' => '2026-02-03', 'amount' => 100000, 'credit_account_id' => $a['repairs'], 'reason' => 'New engine'])
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/assets/register/{$car['id']}/changes", ['kind' => 'revaluation', 'date' => '2026-02-03', 'amount' => 2000000, 'reason' => 'Valuation'])->assertUnprocessable();
        $book = $this->getJson("/api/v1/assets/register/{$car['id']}")->json('data.value.book_value');
        $this->assertEquals(round(960000 - $run['total'] + 100000, 2), $book);
        $this->postJson("/api/v1/assets/register/{$car['id']}/changes", ['kind' => 'impairment', 'date' => '2026-02-04', 'amount' => 900000, 'reason' => 'Accident'])->assertOk();
        $this->assertEquals(900000, $this->getJson("/api/v1/assets/register/{$car['id']}")->json('data.value.book_value'));

        $t = $this->postJson("/api/v1/assets/register/{$car['id']}/transfers", ['kind' => 'internal', 'permanent' => false, 'date' => '2026-02-05', 'return_date' => '2026-02-20',
            'to_employee_id' => $ids['emp'], 'reason' => 'Field work'])->assertOk()->json('data');
        $this->assertSame('Asha M', $this->getJson("/api/v1/assets/register/{$car['id']}")->json('data.holder'));

        $this->actingAs($staff);
        $mine = $this->getJson('/api/v1/self/assets')->assertOk();
        $this->assertSame('Land Cruiser', $mine->json('data.0.name'));
        $this->assertTrue($mine->json('confirmation_due'));
        $this->postJson('/api/v1/self/assets/confirm')->assertJsonPath('data.confirmed', 1);
        $this->post("/api/v1/self/assets/{$car['id']}/report", ['type' => 'fault', 'description' => 'Brakes noisy', 'photo' => UploadedFile::fake()->image('brakes.jpg')], ['Accept' => 'application/json'])->assertOk();
        $this->postJson("/api/v1/self/assets/{$bikes[0]['id']}/report", ['type' => 'fault', 'description' => 'x'])->assertForbidden();

        $this->actingAs($officer);
        $report = $this->getJson('/api/v1/assets/reports')->json('data.0');
        $this->assertTrue($report['has_photo']);
        $this->postJson("/api/v1/assets/reports/{$report['id']}/handle", ['status' => 'resolved', 'response' => 'Pads replaced'])->assertOk();
        $this->postJson("/api/v1/assets/transfers/{$t['id']}/return")->assertOk()->assertJsonPath('data.status', 'returned');
        $this->assertSame('Administration', $this->getJson("/api/v1/assets/register/{$car['id']}")->json('data.department'));

        $this->post("/api/v1/assets/register/{$car['id']}/disposals", ['method' => 'sale', 'date' => '2026-02-15', 'proceeds' => 1000000, 'cash_bank_account_id' => CashBankAccount::withoutGlobalScopes()->value('id'),
            'buyer' => 'Garage Ltd', 'reason' => 'Replaced', 'minutes' => UploadedFile::fake()->create('minutes.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'approved');
        $sold = $this->getJson("/api/v1/assets/register/{$car['id']}")->json('data');
        $this->assertSame('disposed', $sold['status']);
        $this->assertEquals(0, $sold['value']['book_value']);
        $this->assertTrue($sold['disposals'][0]['has_minutes']);
        $this->assertGreaterThan(0, $sold['disposals'][0]['gain_loss']);

        app(CompanyContext::class)->run($company, function () use ($a) {
            $this->assertSame(0, JournalEntry::whereIn('status', ['posted'])->get()->filter(fn ($j) => round($j->lines()->sum('debit') - $j->lines()->sum('credit'), 2) != 0)->count());
            $r = app(FinanceReports::class);
            $register = collect($r->build('asset-register', ['date' => '2026-02-28', 'category_id' => null, 'department_id' => null])['rows']);
            $this->assertEquals(600000, $register->last()['book_value']);
            $moves = collect($r->build('asset-movements', ['from' => '2026-01-01', 'to' => '2026-02-28'])['rows'])->last();
            $this->assertEquals(0, $moves['opening']);
            $this->assertEquals(600000, $moves['closing']);
            $this->assertCount(2, $r->build('asset-disposals', ['from' => '2026-01-01', 'to' => '2026-12-31'])['rows']);
            $this->assertCount(1, $r->build('asset-transfers', ['from' => '2026-01-01', 'to' => '2026-12-31'])['rows']);
            $ledger = fn ($acc) => round((float) \DB::table('journal_entry_lines')->where('account_id', $acc)->sum('debit') - (float) \DB::table('journal_entry_lines')->where('account_id', $acc)->sum('credit'), 2);
            $this->assertEquals(600000, $ledger($a['cost']));
            $this->assertEquals(0, $ledger($a['accum']));
        });
        $this->getJson('/api/v1/assets/dashboard')->assertOk()->assertJsonPath('data.count', 2);
    }
}
