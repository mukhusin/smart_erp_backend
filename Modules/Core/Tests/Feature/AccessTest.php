<?php

namespace Modules\Core\Tests\Feature;

use App\Models\CompanyBranch;
use App\Models\User;
use Database\Seeders\ErpModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\ModuleLock;
use Modules\Core\Models\RoleHandover;
use Modules\Core\Services\AccessService;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

/** Roles, handovers, direct permissions and module locks (company, branch, user). */
class AccessTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ErpModuleSeeder::class);
        [$this->company, $this->admin] = $this->createCompany('Alpha', [
            'branches' => [['name' => 'Head Office', 'is_headquarters' => true], ['name' => 'Arusha']],
            'modules' => [['slug' => 'hr'], ['slug' => 'leave'], ['slug' => 'payroll']],
        ]);
        $this->actingAs($this->admin);
        setPermissionsTeamId($this->company->id); // as SetCompanyContext does for each request
    }

    private function staff(string $branch = 'Head Office', array $roles = []): User
    {
        $user = User::factory()->create([
            'company_id' => $this->company->id,
            'company_branch_id' => CompanyBranch::firstWhere('name', $branch)->id,
            'is_active' => true,
        ]);
        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function access(): AccessService
    {
        $service = app(AccessService::class);
        $service->forget();

        return $service;
    }

    public function test_permissions_come_from_all_assigned_roles(): void
    {
        $user = $this->staff(roles: ['hod', 'employee']);

        $permissions = $this->access()->permissions($user);

        $this->assertContains('leave.approve-hod', $permissions);
        $this->assertContains('self-service.use', $permissions);
        $this->assertNotContains('payroll.approve', $permissions);
        $this->assertSame(['employee', 'hod'], $this->access()->roles($user));
    }

    public function test_permissions_of_disabled_modules_are_not_granted(): void
    {
        $user = $this->staff(roles: ['hod']);

        // roster is not enabled for this company, so roster.manage from the hod role does not apply
        $this->assertNotContains('roster.manage', $this->access()->permissions($user));
        $this->assertFalse(Gate::forUser($user)->allows('roster.manage'));
        $this->assertTrue(Gate::forUser($user)->allows('leave.approve-hod'));
    }

    public function test_direct_user_permission(): void
    {
        $user = $this->staff(roles: ['employee']);
        $user->givePermissionTo('payroll.view');

        $this->assertTrue(Gate::forUser($user)->allows('payroll.view'));
    }

    public function test_handover_grants_the_role_only_while_active(): void
    {
        $hod = $this->staff(roles: ['hod']);
        $cover = $this->staff(roles: ['employee']);
        $role = Role::where('name', 'hod')->where('company_id', $this->company->id)->first();

        $handover = RoleHandover::create([
            'role_id' => $role->id, 'from_user_id' => $hod->id, 'to_user_id' => $cover->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addWeek(), 'reason' => 'Annual leave',
        ]);

        $this->assertTrue($this->access()->can($cover, 'leave.approve-hod'));
        $this->assertSame(['employee', 'hod'], $this->access()->roles($cover));

        $handover->update(['revoked_at' => now()]);
        $this->assertFalse($this->access()->can($cover, 'leave.approve-hod'));

        $handover->update(['revoked_at' => null, 'starts_at' => now()->addDay()]);
        $this->assertFalse($this->access()->can($cover, 'leave.approve-hod'), 'not started yet');
    }

    public function test_module_locked_for_a_branch(): void
    {
        $hq = $this->staff('Head Office', ['hr-officer']);
        $arusha = $this->staff('Arusha', ['hr-officer']);

        ModuleLock::create(['module_slug' => 'payroll', 'company_branch_id' => CompanyBranch::firstWhere('name', 'Arusha')->id]);

        $this->assertTrue($this->access()->canUseModule($hq, 'payroll'));
        $this->assertFalse($this->access()->canUseModule($arusha, 'payroll'));
        $this->assertNotContains('payroll.view', $this->access()->permissions($arusha));
        $this->assertContains('leave.view', $this->access()->permissions($arusha));
    }

    public function test_module_locked_for_one_user(): void
    {
        $a = $this->staff(roles: ['hr-officer']);
        $b = $this->staff(roles: ['hr-officer']);

        ModuleLock::create(['module_slug' => 'leave', 'user_id' => $b->id]);

        $this->assertContains('leave.approve-hr', $this->access()->permissions($a));
        $this->assertNotContains('leave.approve-hr', $this->access()->permissions($b));
    }

    public function test_me_returns_effective_access_and_acting_roles(): void
    {
        $hod = $this->staff(roles: ['hod']);
        $cover = $this->staff(roles: ['employee']);
        RoleHandover::create([
            'role_id' => Role::where('name', 'hod')->where('company_id', $this->company->id)->value('id'),
            'from_user_id' => $hod->id, 'to_user_id' => $cover->id, 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay(),
        ]);

        $this->actingAs($cover)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.roles', ['employee', 'hod'])
            ->assertJsonPath('data.acting_roles.0.role', 'hod')
            ->assertJsonFragment(['leave.approve-hod'])
            ->assertJsonMissing(['roster.manage']);
    }

    public function test_roles_are_separate_per_company(): void
    {
        [$beta] = $this->createCompany('Beta');

        $this->assertSame(2, Role::withoutGlobalScopes()->where('name', 'hod')->count());
        $this->assertNotSame(
            Role::where('name', 'hod')->where('company_id', $this->company->id)->value('id'),
            Role::where('name', 'hod')->where('company_id', $beta->id)->value('id'),
        );
    }
}
