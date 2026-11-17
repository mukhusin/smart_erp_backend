<?php

namespace Modules\Accounting\Tests\Feature;

use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Accounting\Services\PostingService;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class BankReconciliationTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $clerk;

    private User $chief;

    private array $acc = [];

    private int $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-18 09:00:00');
        [$this->company] = $this->createCompany('Alpha', ['modules' => [['slug' => 'admin'], ['slug' => 'accounting']]]);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->run($this->company, function () {
            Role::findOrCreate('bank-clerk', 'web')->syncPermissions(['accounting.view', 'accounting.reconcile']);
            Role::findOrCreate('chief', 'web')->syncPermissions(['accounting.view', 'accounting.manage']);
            $this->clerk = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('bank-clerk');
            $this->chief = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('chief');
            app(FiscalCalendar::class)->createYear('2026-01-01');
            foreach ([['1100', 'CRDB', 'asset'], ['3000', 'Fund balance', 'equity'], ['4100', 'Grants', 'revenue'], ['5100', 'Supplies', 'expense'], ['5900', 'Bank charges', 'expense']] as [$code, $name, $type]) {
                $this->acc[$code] = Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'is_postable' => true, 'is_active' => true])->id;
            }
            $this->bank = CashBankAccount::create(['name' => 'CRDB main', 'kind' => 'bank', 'account_id' => $this->acc['1100'], 'currency' => 'TZS', 'is_active' => true])->id;
            $post = app(PostingService::class);
            $post->post('2026-10-01', 'Opening', [['account_id' => $this->acc['1100'], 'debit' => 1000000], ['account_id' => $this->acc['3000'], 'credit' => 1000000]], $this->chief, 'opening');
            $post->post('2026-10-05', 'Grant received GR-5', [['account_id' => $this->acc['1100'], 'debit' => 400000], ['account_id' => $this->acc['4100'], 'credit' => 400000]], $this->chief);
            $post->post('2026-10-10', 'Cheque 1001 supplies', [['account_id' => $this->acc['5100'], 'debit' => 150000], ['account_id' => $this->acc['1100'], 'credit' => 150000]], $this->chief);
            $post->post('2026-10-28', 'Cheque 1002 supplies', [['account_id' => $this->acc['5100'], 'debit' => 80000], ['account_id' => $this->acc['1100'], 'credit' => 80000]], $this->chief);
            $wrong = $post->post('2026-10-12', 'Wrong entry', [['account_id' => $this->acc['5100'], 'debit' => 5000], ['account_id' => $this->acc['1100'], 'credit' => 5000]], $this->chief);
            $post->reverse($wrong, '2026-10-13', $this->chief, 'mistake');
            $post->post('2026-11-02', 'November receipt', [['account_id' => $this->acc['1100'], 'debit' => 70000], ['account_id' => $this->acc['4100'], 'credit' => 70000]], $this->chief);
            $this->assertTrue(WorkflowTemplate::where('process', 'bank-reconciliation')->exists());
            WorkflowTemplate::where('process', 'bank-reconciliation')->first()->steps()->update(['role_id' => Role::where('name', 'chief')->value('id')]);
        });
    }

    public function test_reconcile_a_month_with_a_statement(): void
    {
        $this->actingAs($this->chief)->postJson('/api/v1/accounting/reconciliations', [])->assertForbidden();
        $id = $this->actingAs($this->clerk)->postJson('/api/v1/accounting/reconciliations', ['cash_bank_account_id' => $this->bank, 'statement_date' => '2026-10-31', 'statement_balance' => 1247500])
            ->assertCreated()->assertJsonPath('data.number', 'BR-2026-00001')->json('data.id');
        $this->postJson('/api/v1/accounting/reconciliations', ['cash_bank_account_id' => $this->bank, 'statement_date' => '2026-11-30', 'statement_balance' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors(['cash_bank_account_id']);

        $show = $this->getJson("/api/v1/accounting/reconciliations/{$id}")->assertOk()->json('data');
        $this->assertCount(6, $show['book']);
        $this->assertSame(1170000.0, (float) $show['summary']['book_balance']);

        $csv = "Date,Description,Reference,Money in,Money out\n01/10/2026,Opening,,1000000,\n06/10/2026,Transfer in,GR-5,400000,\n12/10/2026,Chq 1001,1001,,150000\n31/10/2026,Service charge,,,2500\n";
        $this->post("/api/v1/accounting/reconciliations/{$id}/statement", ['file' => UploadedFile::fake()->createWithContent('stmt.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.added', 4);
        $this->postJson("/api/v1/accounting/reconciliations/{$id}/auto-match")->assertOk()->assertJsonPath('data.matched', 3);

        $show = $this->getJson("/api/v1/accounting/reconciliations/{$id}")->json('data');
        $this->assertSame(1, $show['summary']['unmatched_statement']);
        $this->assertSame(80000.0, (float) $show['summary']['uncleared_payments']);
        $this->assertSame(-2500.0, (float) $show['summary']['difference']);
        $this->postJson("/api/v1/accounting/reconciliations/{$id}/submit")->assertUnprocessable()->assertJsonValidationErrors(['difference']);

        $charge = collect($show['statement'])->firstWhere('journal_entry_line_id', null)['id'];
        $this->postJson("/api/v1/accounting/reconciliations/{$id}/statement/{$charge}/adjust", ['account_id' => $this->acc['5900'], 'description' => 'October bank charges'])->assertOk();
        $show = $this->getJson("/api/v1/accounting/reconciliations/{$id}")->json('data');
        $this->assertSame(0.0, (float) $show['summary']['difference']);
        $this->assertSame(1167500.0, (float) $show['summary']['book_balance']);
        $this->assertTrue(collect($show['statement'])->firstWhere('id', $charge)['adjustment']);

        $this->postJson("/api/v1/accounting/reconciliations/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'pending');
        $wf = WorkflowInstance::where('process', 'bank-reconciliation')->where('status', 'pending')->value('id');
        $this->actingAs($this->chief)->postJson("/api/v1/workflow/requests/{$wf}/approve")->assertOk();
        $done = $this->getJson("/api/v1/accounting/reconciliations/{$id}")->assertJsonPath('data.status', 'completed')->json('data');
        $this->assertSame(0.0, (float) $done['summary']['difference']);
        $this->assertSame(80000.0, (float) $done['summary']['uncleared_payments']);

        $this->actingAs($this->clerk)->postJson('/api/v1/accounting/reconciliations', ['cash_bank_account_id' => $this->bank, 'statement_date' => '2026-10-20', 'statement_balance' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors(['statement_date']);
        $next = $this->postJson('/api/v1/accounting/reconciliations', ['cash_bank_account_id' => $this->bank, 'statement_date' => '2026-11-30', 'statement_balance' => 1237500])->assertCreated()->json('data.id');
        $book = collect($this->getJson("/api/v1/accounting/reconciliations/{$next}")->json('data.book'));
        $this->assertSame(['Cheque 1002 supplies', 'November receipt'], $book->pluck('description')->all());
        $this->putJson("/api/v1/accounting/reconciliations/{$next}/cleared", ['line_ids' => $book->pluck('id')->all(), 'cleared' => true])->assertOk();
        $this->getJson("/api/v1/accounting/reconciliations/{$next}")->assertJsonPath('data.summary.difference', 0);

        $this->actingAs($this->chief)->postJson("/api/v1/accounting/reconciliations/{$id}/reopen")->assertUnprocessable();
        $this->actingAs($this->chief)->getJson('/api/v1/reports/finance/reconciliation-status')->assertOk()->assertJsonPath('data.rows.0.last_date', '31 Oct 2026');
    }

    public function test_bank_feed_pulls_transactions_into_the_reconciliation(): void
    {
        \Illuminate\Support\Facades\Http::fake(['bank.example/*' => \Illuminate\Support\Facades\Http::response(['result' => ['items' => [
            ['txn_id' => 'T1', 'posted' => '2026-10-06', 'narration' => 'Transfer in', 'ref' => 'GR-5', 'cr' => '400,000.00', 'dr' => null],
            ['txn_id' => 'T2', 'posted' => '2026-10-12', 'narration' => 'Chq 1001', 'ref' => '1001', 'cr' => null, 'dr' => '150000'],
            ['txn_id' => 'T3', 'posted' => '2026-11-03', 'narration' => 'Later', 'ref' => null, 'cr' => '70000', 'dr' => null],
        ]]])]);
        $this->actingAs($this->clerk)->putJson("/api/v1/accounting/cash-bank/{$this->bank}/feed", ['enabled' => true, 'url' => 'https://bank.example/tx'])->assertForbidden();
        $this->actingAs($this->chief)->putJson("/api/v1/accounting/cash-bank/{$this->bank}/feed", ['enabled' => true, 'url' => 'https://bank.example/tx', 'token' => 'secret-1',
            'token_header' => 'X-Api-Key', 'params' => ['from' => '{from}', 'to' => '{to}'], 'list_path' => 'result.items', 'id_field' => 'txn_id', 'date_field' => 'posted',
            'description_field' => 'narration', 'reference_field' => 'ref', 'credit_field' => 'cr', 'debit_field' => 'dr', 'days' => 60])->assertOk();
        $this->getJson("/api/v1/accounting/cash-bank/{$this->bank}/feed")->assertJsonPath('data.token_set', true)->assertJsonMissingPath('data.config.token');
        $this->assertStringNotContainsString('secret-1', (string) \DB::table('cash_bank_accounts')->where('id', $this->bank)->value('feed_config'));
        $this->postJson("/api/v1/accounting/cash-bank/{$this->bank}/feed/test")->assertOk()->assertJsonPath('data.count', 3)->assertJsonPath('data.rows.1.amount', -150000);
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r->hasHeader('X-Api-Key', 'secret-1') && str_contains($r->url(), 'from=2026-09-19'));

        $this->artisan('accounting:bank-feeds')->assertSuccessful();
        $this->artisan('accounting:bank-feeds')->assertSuccessful();
        $this->assertSame(3, \Modules\Accounting\Models\BankFeedTransaction::withoutGlobalScopes()->count());

        $id = $this->actingAs($this->clerk)->postJson('/api/v1/accounting/reconciliations', ['cash_bank_account_id' => $this->bank, 'statement_date' => '2026-10-31', 'statement_balance' => 1250000])->assertCreated()->json('data.id');
        $this->assertCount(2, $this->getJson("/api/v1/accounting/reconciliations/{$id}")->json('data.statement'));
        $this->postJson("/api/v1/accounting/reconciliations/{$id}/pull-feed")->assertOk()->assertJsonPath('data.added', 0);
        $this->postJson("/api/v1/accounting/reconciliations/{$id}/auto-match")->assertJsonPath('data.matched', 2);
        $this->deleteJson("/api/v1/accounting/reconciliations/{$id}")->assertOk();
        $this->assertSame(0, \Modules\Accounting\Models\BankFeedTransaction::withoutGlobalScopes()->whereNotNull('bank_statement_line_id')->count());
    }
}
