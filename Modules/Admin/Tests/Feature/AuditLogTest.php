<?php

namespace Modules\Admin\Tests\Feature;

use App\Models\AuditLog;
use App\Models\CompanyBranch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Workflow\Models\WorkflowInstance;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['branches' => [['name' => 'Head Office', 'is_headquarters' => true]], 'modules' => [['slug' => 'hr']]]);
        setPermissionsTeamId($this->company->id);
    }

    private function staff(array $roles = ['employee']): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'is_active' => true,
            'company_branch_id' => CompanyBranch::withoutCompanyScope()->where('company_id', $this->company->id)->value('id')]);
        $user->assignRole($roles);

        return $user;
    }

    public function test_changes_are_logged_with_user_and_ip_and_listed(): void
    {
        $user = $this->staff();
        $this->actingAs($this->admin)->postJson("/api/v1/admin/users/{$user->id}/deactivate", ['reason' => 'Left'])->assertOk();

        $res = $this->getJson("/api/v1/admin/audit-logs?type=user&action=updated&record_id={$user->id}")->assertOk()->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.record_id', $user->id)->assertJsonPath('data.0.type_label', 'User account')
            ->assertJsonPath('data.0.user.id', $this->admin->id)->assertJsonPath('data.0.ip_address', '127.0.0.1')
            ->assertJsonPath('data.0.changes.0', ['field' => 'is_active', 'label' => 'Is active', 'old' => 1, 'new' => false]);
        $this->assertStringContainsString('deactivate', $res->json('data.0.url'));

        $this->assertGreaterThanOrEqual(1, $this->getJson('/api/v1/admin/audit-logs?search='.urlencode($this->admin->username))->json('meta.total'));
        $this->getJson('/api/v1/admin/audit-logs/options')->assertOk()->assertJsonFragment(['value' => 'employee-contract', 'label' => 'Contract']);
    }

    public function test_secrets_are_never_logged_and_import_can_pause_logging(): void
    {
        $user = $this->staff();
        $before = AuditLog::withoutCompanyScope()->count();
        $user->update(['password' => 'new-secret-123']);
        $this->assertSame($before, AuditLog::withoutCompanyScope()->count());

        AuditLog::withoutLogging(fn () => $user->update(['username' => 'renamed']));
        $this->assertSame($before, AuditLog::withoutCompanyScope()->count());
        $this->assertStringNotContainsString('password', (string) AuditLog::withoutCompanyScope()->pluck('new_values')->toJson());
    }

    public function test_workflow_setup_and_decisions_are_logged(): void
    {
        $approver = $this->staff();
        $this->actingAs($this->admin)->putJson('/api/v1/admin/workflows/user-access-change', [
            'company_branch_id' => null, 'is_active' => true, 'steps' => [['name' => 'Check', 'approver_type' => 'user', 'user_id' => $approver->id]],
        ])->assertOk();
        $this->assertSame(1, $this->getJson('/api/v1/admin/audit-logs?type=workflow-step&action=created&user_id='.$this->admin->id)->json('meta.total'));

        $user = $this->staff();
        $this->putJson("/api/v1/admin/users/{$user->id}/roles", ['roles' => ['hod'], 'reason' => 'Promotion'])->assertOk();
        $id = WorkflowInstance::withoutCompanyScope()->latest('id')->value('id');
        $this->actingAs($approver)->postJson("/api/v1/workflow/requests/{$id}/approve", ['comment' => 'Sawa'])->assertOk();

        $decision = $this->actingAs($this->admin)->getJson('/api/v1/admin/audit-logs?type=workflow-instance-step')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame($approver->id, $decision['user']['id']);
        $this->assertSame('approved', collect($decision['changes'])->firstWhere('field', 'status')['new']);
    }

    public function test_needs_permission(): void
    {
        $this->actingAs($this->staff())->getJson('/api/v1/admin/audit-logs')->assertForbidden();
    }
}
