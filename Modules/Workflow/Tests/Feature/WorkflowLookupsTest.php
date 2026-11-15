<?php

namespace Modules\Workflow\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class WorkflowLookupsTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_workflow_managers_get_branches_roles_and_users_without_user_admin_rights(): void
    {
        [$company] = $this->createCompany('Alpha', ['modules' => [['slug' => 'admin'], ['slug' => 'hr']]]);
        setPermissionsTeamId($company->id);
        $manager = User::factory()->create(['company_id' => $company->id, 'username' => 'manager']);
        $manager->assignRole('hr-manager');
        $this->assertFalse($manager->can('admin.users.view'));

        $this->actingAs($manager)->getJson('/api/v1/admin/workflows/options')->assertOk()
            ->assertJsonPath('data.branches.0.name', 'Head Office')->assertJsonFragment(['name' => 'hr-officer']);
        $this->getJson('/api/v1/admin/workflows/users?search=manag')->assertOk()->assertJsonPath('data.0.id', $manager->id);

        $plain = User::factory()->create(['company_id' => $company->id]);
        $plain->assignRole('employee');
        $this->actingAs($plain)->getJson('/api/v1/admin/workflows/options')->assertForbidden();
    }
}
