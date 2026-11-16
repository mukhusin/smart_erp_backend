<?php

namespace Modules\Accounting\Tests\Feature;

use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\AccountingPolicies;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Accounting\Services\PostingService;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class FrameworkTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_framework_profiles_limit_policies_and_change_wording(): void
    {
        $this->travelTo('2026-11-18 09:00:00');
        [$company] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'accounting']]]);
        setPermissionsTeamId($company->id);
        $user = app(CompanyContext::class)->run($company, function () use ($company) {
            Role::findOrCreate('acc', 'web')->syncPermissions(['accounting.view', 'accounting.journals', 'accounting.manage']);
            $u = tap(User::factory()->create(['company_id' => $company->id]))->assignRole('acc');
            app(FiscalCalendar::class)->createYear('2026-01-01');
            app(FiscalCalendar::class)->createYear('2027-01-01');

            return $u;
        });

        $this->actingAs($user)->getJson('/api/v1/accounting/framework')->assertOk()->assertJsonPath('data.framework', 'ifrs_sme')
            ->assertJsonPath('data.policies.terminology', 'nonprofit')->assertJsonPath('data.wording.performance', 'Statement of income and expenditure')->assertJsonCount(5, 'profiles');
        $this->putJson('/api/v1/accounting/framework', ['framework' => 'ipsas', 'policies' => ['inventory_costing' => 'lifo']])->assertUnprocessable()->assertJsonValidationErrors(['policies.inventory_costing']);
        $this->putJson('/api/v1/accounting/framework', ['framework' => 'local', 'policies' => ['inventory_costing' => 'lifo']])->assertUnprocessable()->assertJsonValidationErrors(['framework_name']);
        $this->putJson('/api/v1/accounting/framework', ['framework' => 'us_gaap', 'policies' => ['inventory_costing' => 'lifo'], 'reason' => 'US donor reporting'])->assertOk();
        $this->getJson('/api/v1/accounting/framework')->assertJsonPath('data.framework', 'us_gaap')->assertJsonPath('data.policies.inventory_costing', 'lifo')
            ->assertJsonPath('data.policies.asset_measurement', 'cost')->assertJsonPath('data.wording.position', 'Balance sheet')->assertJsonCount(1, 'history');

        app(CompanyContext::class)->run($company, function () use ($user) {
            $a = Account::create(['code' => '1100', 'name' => 'Bank', 'type' => 'asset', 'is_postable' => true, 'is_active' => true])->id;
            $b = Account::create(['code' => '3000', 'name' => 'Fund', 'type' => 'equity', 'is_postable' => true, 'is_active' => true])->id;
            app(PostingService::class)->post('2026-02-01', 'Opening', [['account_id' => $a, 'debit' => 100], ['account_id' => $b, 'credit' => 100]], $user, 'opening');
        });
        $this->putJson('/api/v1/accounting/framework', ['framework' => 'ipsas', 'effective_from' => '2026-11-18'])->assertUnprocessable()->assertJsonValidationErrors(['effective_from']);
        $this->putJson('/api/v1/accounting/framework', ['framework' => 'ipsas', 'effective_from' => '2027-01-01', 'reason' => 'Becoming a public hospital'])->assertOk();
        $this->getJson('/api/v1/accounting/framework')->assertJsonPath('data.framework', 'us_gaap')->assertJsonCount(2, 'history');
        app(CompanyContext::class)->run($company, function () {
            $p = app(AccountingPolicies::class);
            $this->assertSame('ipsas', $p->at('2027-03-01')['framework']);
            $this->assertSame('required', $p->policy('budget_statement', '2027-03-01'));
            $this->assertSame('Statement of financial performance', $p->wording('2027-03-01')['performance']);
            $this->assertSame('lifo', $p->policy('inventory_costing', '2026-06-01'));
        });
    }
}
