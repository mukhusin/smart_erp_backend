<?php

namespace Modules\Admin\Tests\Feature;

use App\Models\CompanyBranch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AccessChange;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class CompanyAdminTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->admin] = $this->createCompany('Alpha', [
            'branches' => [['name' => 'Head Office', 'is_headquarters' => true], ['name' => 'Arusha']],
            'modules' => [['slug' => 'hr'], ['slug' => 'leave'], ['slug' => 'payroll']],
        ]);
        setPermissionsTeamId($this->company->id);
    }

    private function staff(array $roles = ['employee'], string $branch = 'Head Office'): User
    {
        $user = User::factory()->create([
            'company_id' => $this->company->id,
            'company_branch_id' => CompanyBranch::withoutCompanyScope()->where('company_id', $this->company->id)->where('name', $branch)->value('id'),
            'is_active' => true,
        ]);
        $user->assignRole($roles);

        return $user;
    }

    public function test_lists_only_own_company_users_with_roles(): void
    {
        $this->staff(['hod']);
        [, $other] = $this->createCompany('Beta');
        setPermissionsTeamId($this->company->id);

        $res = $this->actingAs($this->admin)->getJson('/api/v1/admin/users?filter[role]=hod')->assertOk();
        $res->assertJsonCount(1, 'data')->assertJsonPath('data.0.roles', ['hod']);

        $this->actingAs($this->admin)->getJson("/api/v1/admin/users/{$other->id}")->assertNotFound();
    }

    public function test_assign_several_roles_and_history_is_recorded(): void
    {
        $user = $this->staff();

        $this->actingAs($this->admin)->putJson("/api/v1/admin/users/{$user->id}/roles", ['roles' => ['hod', 'hr-officer'], 'reason' => 'Promoted'])
            ->assertOk()->assertJsonPath('data.roles', ['hod', 'hr-officer']);

        $change = AccessChange::withoutCompanyScope()->where('user_id', $user->id)->first();
        $this->assertSame(['employee'], $change->before);
        $this->assertSame(['hod', 'hr-officer'], $change->after);
        $this->assertSame('applied', $change->status);
        $this->assertSame($this->admin->id, $change->requested_by);

        $this->actingAs($this->admin)->getJson("/api/v1/admin/users/{$user->id}/access-history")
            ->assertOk()->assertJsonPath('data.0.type', 'roles')->assertJsonPath('data.0.reason', 'Promoted');
    }

    public function test_roles_of_another_company_cannot_be_assigned(): void
    {
        $this->createCompany('Beta');
        Role::create(['name' => 'beta-only', 'guard_name' => 'web', 'company_id' => 999]);
        setPermissionsTeamId($this->company->id);
        $user = $this->staff();

        $this->actingAs($this->admin)->putJson("/api/v1/admin/users/{$user->id}/roles", ['roles' => ['beta-only']])
            ->assertUnprocessable()->assertJsonValidationErrors('roles.0');
    }

    public function test_create_custom_role_with_permissions_and_delete_it(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/admin/roles', [
            'name' => 'payroll-clerk', 'description' => 'Prepares payroll', 'permissions' => ['payroll.view', 'payroll.create'],
        ])->assertCreated()->assertJsonPath('data.permissions', ['payroll.create', 'payroll.view'])->json('data.id');

        $user = $this->staff(['payroll-clerk']);
        $this->actingAs($user)->getJson('/api/v1/me')->assertJsonFragment(['payroll.create']);

        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/roles/{$id}")->assertUnprocessable();
        $user->syncRoles([]);
        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/roles/{$id}")->assertNoContent();
    }

    public function test_company_admin_role_and_built_in_roles_are_protected(): void
    {
        $adminRole = Role::where('name', 'company-admin')->where('company_id', $this->company->id)->first();
        $hod = Role::where('name', 'hod')->where('company_id', $this->company->id)->first();

        $this->actingAs($this->admin)->putJson("/api/v1/admin/roles/{$adminRole->id}", ['name' => 'company-admin', 'permissions' => []])
            ->assertUnprocessable();
        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/roles/{$hod->id}")->assertUnprocessable();
    }

    public function test_deactivate_requires_reason_and_blocks_the_user(): void
    {
        $user = $this->staff(['hod']);

        $this->actingAs($this->admin)->postJson("/api/v1/admin/users/{$user->id}/deactivate")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($this->admin)->postJson("/api/v1/admin/users/{$user->id}/deactivate", ['reason' => 'Left the company'])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->actingAs($user->fresh())->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'account_deactivated');

        $this->actingAs($this->admin)->postJson("/api/v1/admin/users/{$user->id}/activate")->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_cannot_deactivate_yourself_or_remove_the_last_company_admin(): void
    {
        $this->actingAs($this->admin)->postJson("/api/v1/admin/users/{$this->admin->id}/deactivate", ['reason' => 'x'])->assertUnprocessable();
        $this->actingAs($this->admin)->putJson("/api/v1/admin/users/{$this->admin->id}/roles", ['roles' => ['employee']])
            ->assertUnprocessable()->assertJsonValidationErrors('user');

        $second = $this->staff(['company-admin']);
        $this->actingAs($this->admin)->putJson("/api/v1/admin/users/{$this->admin->id}/roles", ['roles' => ['employee']])->assertOk();
        $this->assertTrue($second->fresh()->hasRole('company-admin'));
    }

    public function test_there_is_no_way_to_edit_or_delete_user_details(): void
    {
        $user = $this->staff();

        $this->actingAs($this->admin)->putJson("/api/v1/admin/users/{$user->id}", ['username' => 'hacked'])->assertStatus(405);
        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/users/{$user->id}")->assertStatus(405);
    }

    public function test_handover_and_module_lock_through_the_api(): void
    {
        $hod = $this->staff(['hod']);
        $cover = $this->staff();
        $hodRole = Role::where('name', 'hod')->where('company_id', $this->company->id)->value('id');

        $id = $this->actingAs($this->admin)->postJson('/api/v1/admin/handovers', [
            'role_id' => $hodRole, 'from_user_id' => $hod->id, 'to_user_id' => $cover->id,
            'starts_at' => now()->subMinute()->toIso8601String(), 'ends_at' => now()->addWeek()->toIso8601String(), 'reason' => 'Annual leave',
        ])->assertCreated()->assertJsonPath('data.status', 'active')->json('data.id');

        $this->actingAs($cover)->getJson('/api/v1/me')->assertJsonFragment(['leave.approve-hod']);
        $this->actingAs($this->admin)->postJson("/api/v1/admin/handovers/{$id}/revoke")->assertOk()->assertJsonPath('data.status', 'ended');

        $arusha = CompanyBranch::where('name', 'Arusha')->value('id');
        $this->actingAs($this->admin)->postJson('/api/v1/admin/module-locks', ['module_slug' => 'payroll', 'company_branch_id' => $arusha])->assertCreated();
        $this->actingAs($this->admin)->postJson('/api/v1/admin/module-locks', ['module_slug' => 'payroll', 'company_branch_id' => $arusha, 'user_id' => $cover->id])
            ->assertUnprocessable();
        $arushaUser = $this->staff(['hr-officer'], 'Arusha');
        $this->actingAs($arushaUser)->getJson('/api/v1/me')->assertJsonMissing(['payroll.view']);
    }

    public function test_permissions_catalog_only_shows_enabled_modules(): void
    {
        $modules = collect($this->actingAs($this->admin)->getJson('/api/v1/admin/permissions')->assertOk()->json('data'))->pluck('module');

        $this->assertTrue($modules->contains('payroll'));
        $this->assertTrue($modules->contains('admin'));
        $this->assertFalse($modules->contains('fleet'));
    }

    public function test_users_without_admin_permissions_are_forbidden(): void
    {
        $user = $this->staff(['employee']);

        $this->actingAs($user)->getJson('/api/v1/admin/users')->assertForbidden();
        $this->actingAs($user)->postJson('/api/v1/admin/roles', ['name' => 'x', 'permissions' => []])->assertForbidden();
        $this->actingAs($this->createPlatformAdmin())->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_login_announcements_for_own_company(): void
    {
        $id = $this->actingAs($this->admin)->post('/api/v1/admin/login-slides', ['title' => 'Payday moved', 'content' => 'Salaries on the 25th.', 'is_active' => 'true'], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');

        $this->getJson("/api/v1/auth/login-branding?org={$this->company->slug}")->assertJsonFragment(['title' => 'Payday moved']);
        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/login-slides/{$id}")->assertNoContent();
    }
}
