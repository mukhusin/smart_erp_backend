<?php

namespace Modules\Accounting\Tests\Feature;

use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Workflow\Models\WorkflowTemplate;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class AdjustingJournalTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_accrual_reverses_itself_and_trial_balance_shows_both_stages(): void
    {
        $this->travelTo('2026-10-31 09:00:00');
        [$company] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'accounting'], ['slug' => 'reports']]]);
        setPermissionsTeamId($company->id);
        [$accountant, $acc] = app(CompanyContext::class)->run($company, function () use ($company) {
            app(FiscalCalendar::class)->createYear('2026-07-01');
            WorkflowTemplate::where('process', 'manual-journal')->delete();
            $acc = [];
            foreach ([['1100', 'Bank', 'asset'], ['2110', 'Accrued expenses', 'liability'], ['3000', 'Fund', 'equity'], ['5300', 'Electricity', 'expense']] as [$c, $n, $t]) {
                $acc[$c] = Account::create(['code' => $c, 'name' => $n, 'type' => $t, 'is_postable' => true, 'is_active' => true])->id;
            }

            return [tap(User::factory()->create(['company_id' => $company->id]))->assignRole('finance-manager'), $acc];
        });
        $this->actingAs($accountant);

        $cash = $this->postJson('/api/v1/accounting/journals', ['date' => '2026-10-05', 'description' => 'Capital', 'lines' => [
            ['account_id' => $acc['1100'], 'debit' => 1000000], ['account_id' => $acc['3000'], 'credit' => 1000000]]])->json('data.id');
        $this->postJson("/api/v1/accounting/journals/{$cash}/submit")->assertOk();

        $body = ['date' => '2026-10-31', 'description' => 'October electricity not yet billed', 'adjustment' => 'accrual', 'lines' => [
            ['account_id' => $acc['5300'], 'debit' => 300000], ['account_id' => $acc['2110'], 'credit' => 300000]]];
        $this->postJson('/api/v1/accounting/journals', $body + ['reverse_on' => '2026-10-31'])->assertUnprocessable()->assertJsonValidationErrors(['reverse_on']);
        $this->postJson('/api/v1/accounting/journals', ['adjustment' => 'guess'] + $body)->assertUnprocessable()->assertJsonValidationErrors(['adjustment']);
        $id = $this->postJson('/api/v1/accounting/journals', $body + ['reverse_on' => '2026-11-01'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/accounting/journals/{$id}/submit")->assertOk();
        $this->getJson("/api/v1/accounting/journals/{$id}")->assertJsonPath('data.status', 'posted')->assertJsonPath('data.adjustment_label', 'Accrual')
            ->assertJsonPath('data.reverse_on', '2026-11-01')->assertJsonPath('data.reversed_by', null);
        $this->getJson('/api/v1/accounting/journals?adjusting=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.adjustment', 'accrual');

        $tb = fn (string $stage, string $to) => collect($this->getJson("/api/v1/reports/finance/trial-balance?from=2026-10-01&to={$to}&stage={$stage}")->assertOk()->json('data.rows'))->keyBy('code');
        $this->assertSame(300000, $tb('adjusted', '2026-10-31')['5300']['debit']);
        $this->assertArrayNotHasKey('5300', $tb('unadjusted', '2026-10-31')->all());
        $this->assertSame(1000000, $tb('unadjusted', '2026-10-31')['']['debit']);

        $this->travelTo('2026-11-01 00:45:00');
        $this->artisan('accounting:reverse-adjustments')->assertSuccessful();
        $this->artisan('accounting:reverse-adjustments')->assertSuccessful();
        $reversal = $this->getJson("/api/v1/accounting/journals/{$id}")->assertJsonPath('data.status', 'reversed')->json('data.reversed_by.id');
        $this->getJson("/api/v1/accounting/journals/{$reversal}")->assertJsonPath('data.date', '2026-11-01')->assertJsonPath('data.adjustment', 'accrual');
        $this->assertSame(1, app(CompanyContext::class)->run($company, fn () => JournalEntry::where('reversal_of_id', $id)->count()));
        $this->assertArrayNotHasKey('5300', $tb('adjusted', '2026-11-30')->all());
        $this->assertArrayNotHasKey('2110', $tb('unadjusted', '2026-11-30')->all());

        $late = $this->postJson('/api/v1/accounting/journals', ['date' => '2026-10-31', 'description' => 'Prepaid insurance', 'adjustment' => 'prepayment', 'reverse_on' => '2026-11-01', 'lines' => [
            ['account_id' => $acc['2110'], 'debit' => 50000], ['account_id' => $acc['1100'], 'credit' => 50000]]])->json('data.id');
        $this->postJson("/api/v1/accounting/journals/{$late}/submit")->assertOk();
        $this->getJson("/api/v1/accounting/journals/{$late}")->assertJsonPath('data.status', 'reversed');
    }
}
