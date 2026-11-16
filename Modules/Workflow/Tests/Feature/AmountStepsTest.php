<?php

namespace Modules\Workflow\Tests\Feature;

use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Workflow\Models\WorkflowInstance;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class AmountStepsTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_steps_with_a_minimum_amount_apply_only_to_larger_requests(): void
    {
        $this->travelTo('2026-11-18 09:00:00');
        [$company, $admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'admin'], ['slug' => 'accounting']]]);
        setPermissionsTeamId($company->id);
        $accountant = null;
        $ids = [];
        app(CompanyContext::class)->run($company, function () use (&$accountant, &$ids, $company) {
            Role::findOrCreate('accountant', 'web')->syncPermissions(['accounting.view', 'accounting.journals', 'accounting.manage']);
            Role::findOrCreate('director-general', 'web');
            $accountant = tap(User::factory()->create(['company_id' => $company->id]))->assignRole('accountant');
            app(FiscalCalendar::class)->createYear('2026-01-01');
            $ids['bank'] = Account::create(['code' => '1100', 'name' => 'Bank', 'type' => 'asset', 'is_postable' => true, 'is_active' => true])->id;
            $ids['cap'] = Account::create(['code' => '3000', 'name' => 'Capital', 'type' => 'equity', 'is_postable' => true, 'is_active' => true])->id;
        });

        $this->actingAs($admin)->getJson('/api/v1/admin/workflows')->assertOk();
        $this->putJson('/api/v1/admin/workflows/manual-journal', ['is_active' => true, 'steps' => [
            ['name' => 'Chief accountant', 'approver_type' => 'role', 'role_id' => Role::where('name', 'company-admin')->value('id')],
            ['name' => 'Director general', 'approver_type' => 'role', 'role_id' => Role::where('name', 'director-general')->value('id'), 'min_amount' => 1000000],
        ]])->assertOk()->assertJsonPath('data.steps.1.min_amount', 1000000);

        $journal = fn (float $amount) => $this->actingAs($accountant)->postJson('/api/v1/accounting/journals', ['date' => '2026-11-10', 'description' => 'Capital', 'lines' => [
            ['account_id' => $ids['bank'], 'debit' => $amount], ['account_id' => $ids['cap'], 'credit' => $amount],
        ]])->json('data.id');
        $small = $journal(200000);
        $this->postJson("/api/v1/accounting/journals/{$small}/submit")->assertOk();
        $big = $journal(5000000);
        $this->postJson("/api/v1/accounting/journals/{$big}/submit")->assertOk();

        $this->assertSame(1, WorkflowInstance::where('subject_id', $small)->first()->steps()->count());
        $bigFlow = WorkflowInstance::where('subject_id', $big)->first();
        $this->assertSame(2, $bigFlow->steps()->count());
        $this->assertSame(5000000.0, (float) $bigFlow->amount);
    }
}
