<?php

namespace Modules\Audit\Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Accounting\Services\PostingService;
use Modules\Audit\Models\AuditEngagement;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class AuditAccessTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_company_admin_internal_and_external_auditors(): void
    {
        $this->travelTo('2026-11-20 09:00:00');
        [$company, $admin] = $this->createCompany('Alpha', ['full_access' => false, 'modules' => array_map(fn ($s) => ['slug' => $s], ['hr', 'admin', 'accounting', 'payroll', 'audit'])]);
        setPermissionsTeamId($company->id);
        [$auditor, $accountant, $journal2025] = app(CompanyContext::class)->run($company, function () use ($company) {
            $dept = Department::create(['name' => 'Internal audit', 'is_active' => true]);
            $auditor = tap(User::factory()->create(['company_id' => $company->id]))->assignRole('internal-auditor');
            Employee::create(['employee_no' => 'IA1', 'fname' => 'Ida', 'lname' => 'Auditor', 'gender' => 'Female', 'department_id' => $dept->id, 'user_id' => $auditor->id,
                'employment_status' => 'confirmed', 'date_of_birth' => '1985-01-01']);
            $accountant = tap(User::factory()->create(['company_id' => $company->id]))->assignRole('accountant');
            app(FiscalCalendar::class)->createYear('2025-01-01');
            app(FiscalCalendar::class)->createYear('2026-01-01');
            $bank = Account::create(['code' => '1100', 'name' => 'Bank', 'type' => 'asset', 'is_postable' => true, 'is_active' => true])->id;
            $fund = Account::create(['code' => '3000', 'name' => 'Fund', 'type' => 'equity', 'is_postable' => true, 'is_active' => true])->id;
            $old = app(PostingService::class)->post('2025-06-01', 'Old year', [['account_id' => $bank, 'debit' => 100], ['account_id' => $fund, 'credit' => 100]], $accountant);
            app(PostingService::class)->post('2026-09-01', 'Big adjustment', [['account_id' => $bank, 'debit' => 12000000], ['account_id' => $fund, 'credit' => 12000000]], $accountant);
            WorkflowTemplate::where('process', 'audit-engagement')->delete();

            return [$auditor, $accountant, $old->id];
        });

        $this->actingAs($admin)->getJson('/api/v1/accounting/journals')->assertForbidden();
        $this->getJson('/api/v1/admin/workflows')->assertOk();
        $this->assertFalse($admin->can('payroll.view'));

        $this->actingAs($auditor)->getJson('/api/v1/accounting/journals')->assertOk();
        $this->postJson('/api/v1/accounting/journals', ['date' => '2026-11-20', 'description' => 'x', 'lines' => []])->assertForbidden()->assertJsonPath('code', 'auditor_read_only');
        $emp = Employee::withoutGlobalScopes()->where('employee_no', 'IA1')->value('id');
        $this->getJson("/api/v1/hr/employees/{$emp}/dependants")->assertForbidden();
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('user_id', $auditor->id)->where('action', 'viewed')->exists());

        $dash = $this->getJson('/api/v1/audit/dashboard?from=2026-01-01&to=2026-12-31')->assertOk()->assertJsonPath('me.kind', 'internal')->json('data.groups');
        $checks = collect($dash)->flatMap(fn ($g) => $g['checks'])->keyBy('key');
        $this->assertSame(1, $checks['large_manual']['count']);
        $this->assertGreaterThanOrEqual(1, $checks['admins']['count']);
        $this->getJson('/api/v1/audit/checks/large_manual?from=2026-01-01&to=2026-12-31')->assertOk()->assertJsonPath('data.0.description', 'Big adjustment');

        $q = $this->postJson('/api/v1/audit/queries', ['title' => 'Support for big adjustment', 'question' => 'Please share the board approval.', 'assigned_to' => $accountant->id,
            'link' => '/accounting/journals', 'area' => 'ledger', 'due_date' => '2026-11-30'])->assertOk()->json('data.id');
        $this->actingAs($accountant)->getJson('/api/v1/audit/queries')->assertOk()->assertJsonPath('data.0.can.respond', true);
        $this->postJson("/api/v1/audit/queries/{$q}/respond", ['response' => 'Minutes attached in the journal.'])->assertOk()->assertJsonPath('data.status', 'answered');
        $this->actingAs($auditor)->postJson("/api/v1/audit/queries/{$q}/close", ['note' => 'Agreed'])->assertOk()->assertJsonPath('data.status', 'closed');

        Mail::fake();
        $this->actingAs($admin)->postJson('/api/v1/audit/engagements', ['firm' => 'Big Four & Co', 'auditor_name' => 'Ezra External', 'email' => 'ezra@bigfour.example',
            'period_from' => '2026-01-01', 'period_to' => '2026-12-31', 'access_until' => '2026-12-15'])->assertOk()->assertJsonPath('data.status', 'active');
        $external = User::where('email', 'ezra@bigfour.example')->firstOrFail();
        $this->assertSame('Ezra External (Big Four & Co)', $external->display_name);
        Mail::assertSent(\Modules\Core\Mail\StaffInviteMail::class);

        $this->actingAs($external);
        $journals = collect($this->getJson('/api/v1/accounting/journals')->assertOk()->json('data'));
        $this->assertSame(['Big adjustment'], $journals->pluck('description')->all());
        $this->getJson("/api/v1/accounting/journals/{$journal2025}")->assertNotFound();
        app(CompanyContext::class)->run($company, function () {
            $this->assertSame(1, \Modules\Accounting\Models\JournalEntry::query()->inAuditPeriod('date')->count());
            $this->assertSame(2, \Modules\Accounting\Models\JournalEntry::count());
        });
        $this->getJson('/api/v1/payroll/sheets')->assertForbidden();
        $this->getJson('/api/v1/audit/payroll')->assertOk();
        $this->getJson('/api/v1/audit/dashboard')->assertOk()->assertJsonPath('me.kind', 'external')->assertJsonPath('me.period.from', '2026-01-01');
        $this->postJson('/api/v1/audit/engagements', ['firm' => 'x', 'auditor_name' => 'x', 'email' => 'x@x.example', 'period_from' => '2026-01-01', 'period_to' => '2026-01-02', 'access_until' => '2026-12-01'])->assertForbidden();

        $this->travelTo('2026-12-16 09:00:00');
        $this->getJson('/api/v1/audit/dashboard')->assertUnauthorized()->assertJsonPath('code', 'audit_access_ended');
        $this->artisan('audit:daily')->assertSuccessful();
        app(CompanyContext::class)->run($company, fn () => $this->assertSame('ended', AuditEngagement::first()->status));
        $this->assertFalse($external->fresh()->is_active);
    }
}
