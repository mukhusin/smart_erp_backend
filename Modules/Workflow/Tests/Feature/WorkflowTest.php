<?php

namespace Modules\Workflow\Tests\Feature;

use App\Models\CompanyBranch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\HeadOfDepartment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AccessChange;
use Modules\Core\Models\RoleHandover;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->admin] = $this->createCompany('Alpha', [
            'branches' => [['name' => 'Head Office', 'is_headquarters' => true], ['name' => 'Arusha']],
        ]);
        setPermissionsTeamId($this->company->id);
    }

    private function staff(array $roles = ['employee'], string $branch = 'Head Office'): User
    {
        $user = User::factory()->create([
            'company_id' => $this->company->id,
            'company_branch_id' => CompanyBranch::where('name', $branch)->value('id'),
        ]);
        $user->assignRole($roles);

        return $user;
    }

    private function roleId(string $name): int
    {
        return Role::where('name', $name)->where('company_id', $this->company->id)->value('id');
    }

    private function configure(array $steps, ?int $branchId = null): void
    {
        $this->actingAs($this->admin)->putJson('/api/v1/admin/workflows/user-access-change', [
            'company_branch_id' => $branchId, 'is_active' => true, 'steps' => $steps,
        ])->assertOk();
    }

    private function requestRoles(User $user, array $roles): int
    {
        $this->actingAs($this->admin)->putJson("/api/v1/admin/users/{$user->id}/roles", ['roles' => $roles, 'reason' => 'Promotion'])->assertOk();

        return \Modules\Workflow\Models\WorkflowInstance::withoutCompanyScope()->latest('id')->value('id');
    }

    public function test_process_list_and_setup_needs_permission(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/admin/workflows')
            ->assertOk()->assertJsonPath('data.0.process', 'user-access-change')->assertJsonPath('data.0.workflows', []);

        $this->actingAs($this->staff())->getJson('/api/v1/admin/workflows')->assertForbidden();
    }

    public function test_two_step_approval_applies_the_change_only_at_the_end(): void
    {
        $hod = $this->staff(['hod']);
        $director = $this->staff();
        $this->configure([
            ['name' => 'HOD review', 'approver_type' => 'role', 'role_id' => $this->roleId('hod'), 'approve_label' => 'Recommend'],
            ['name' => 'Director approval', 'approver_type' => 'user', 'user_id' => $director->id],
        ]);
        $target = $this->staff();

        $id = $this->requestRoles($target, ['employee', 'hr-officer']);
        $this->assertSame(['employee'], $target->fresh()->getRoleNames()->all(), 'not applied yet');
        $this->assertSame('pending', AccessChange::withoutCompanyScope()->latest('id')->value('status'));

        // The requester cannot approve their own request; the HOD sees it in the inbox.
        $this->actingAs($this->admin)->postJson("/api/v1/workflow/requests/{$id}/approve")->assertUnprocessable();
        $this->actingAs($hod)->getJson('/api/v1/workflow/inbox')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', "Change roles of {$target->display_name}")
            ->assertJsonPath('data.0.current_step.approve_label', 'Recommend')
            ->assertJsonFragment(['Add: hr-officer']);
        $this->actingAs($director)->getJson('/api/v1/workflow/inbox/count')->assertJsonPath('count', 0);

        $this->actingAs($hod)->postJson("/api/v1/workflow/requests/{$id}/approve", ['comment' => 'Fine'])->assertOk()->assertJsonPath('status', null)
            ->assertJsonPath('data.status', 'pending');
        $this->assertSame(['employee'], $target->fresh()->getRoleNames()->all());

        $this->actingAs($director)->postJson("/api/v1/workflow/requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(['employee', 'hr-officer'], $target->fresh()->getRoleNames()->sort()->values()->all());
        $this->assertSame('applied', AccessChange::withoutCompanyScope()->latest('id')->value('status'));
    }

    public function test_reject_needs_a_comment_and_leaves_access_unchanged(): void
    {
        $hod = $this->staff(['hod']);
        $this->configure([['name' => 'HOD review', 'approver_type' => 'role', 'role_id' => $this->roleId('hod')]]);
        $target = $this->staff();
        $id = $this->requestRoles($target, ['hod']);

        $this->actingAs($hod)->postJson("/api/v1/workflow/requests/{$id}/reject")->assertUnprocessable()->assertJsonValidationErrors('comment');
        $this->actingAs($hod)->postJson("/api/v1/workflow/requests/{$id}/reject", ['comment' => 'Not yet'])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame(['employee'], $target->fresh()->getRoleNames()->all());
        $this->assertSame('rejected', AccessChange::withoutCompanyScope()->latest('id')->value('status'));
    }

    public function test_send_back_to_an_earlier_step(): void
    {
        $hod = $this->staff(['hod']);
        $director = $this->staff();
        $this->configure([
            ['name' => 'HOD review', 'approver_type' => 'role', 'role_id' => $this->roleId('hod')],
            ['name' => 'Director approval', 'approver_type' => 'user', 'user_id' => $director->id],
        ]);
        $id = $this->requestRoles($this->staff(), ['hod']);
        $this->actingAs($hod)->postJson("/api/v1/workflow/requests/{$id}/approve")->assertOk();

        $this->actingAs($director)->getJson("/api/v1/workflow/requests/{$id}")->assertJsonPath('data.send_back_options', [['step_order' => 1, 'name' => 'HOD review']]);
        $this->actingAs($director)->postJson("/api/v1/workflow/requests/{$id}/send-back", ['to_step' => 1, 'comment' => 'Check the dates'])
            ->assertOk()->assertJsonPath('data.status', 'pending');

        $this->actingAs($hod)->getJson('/api/v1/workflow/inbox')->assertJsonCount(1, 'data');
        $steps = collect($this->actingAs($hod)->getJson("/api/v1/workflow/requests/{$id}")->json('data.steps'))->pluck('status')->all();
        $this->assertSame(['approved', 'sent_back', 'pending', 'waiting'], $steps);
    }

    public function test_someone_acting_through_a_handover_can_approve(): void
    {
        $hod = $this->staff(['hod']);
        $cover = $this->staff();
        $this->actingAs($this->admin);
        RoleHandover::create(['role_id' => $this->roleId('hod'), 'from_user_id' => $hod->id, 'to_user_id' => $cover->id, 'starts_at' => now()->subHour(), 'reason' => 'Leave']);
        $this->configure([['name' => 'HOD review', 'approver_type' => 'role', 'role_id' => $this->roleId('hod')]]);
        $id = $this->requestRoles($this->staff(), ['hod']);

        $this->actingAs($cover)->postJson("/api/v1/workflow/requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_head_of_the_requesters_department_approves(): void
    {
        $this->actingAs($this->admin);
        $nursing = Department::create(['name' => 'Nursing']);
        $head = $this->staff();
        $headEmployee = Employee::create(['employee_no' => 'H1', 'fname' => 'Head', 'lname' => 'Nurse', 'gender' => 'Female', 'department_id' => $nursing->id, 'user_id' => $head->id]);
        HeadOfDepartment::create(['employee_id' => $headEmployee->id, 'department_id' => $nursing->id, 'level' => 1, 'is_active' => true]);
        $nurse = $this->staff();
        Employee::create(['employee_no' => 'N1', 'fname' => 'A', 'lname' => 'Nurse', 'gender' => 'Female', 'department_id' => $nursing->id, 'user_id' => $nurse->id]);
        $other = $this->staff(['hod']);

        $this->configure([['name' => 'Head of department', 'approver_type' => 'department_head']]);
        $id = $this->requestRoles($nurse, ['hod']);

        $this->actingAs($other)->postJson("/api/v1/workflow/requests/{$id}/approve")->assertUnprocessable();
        $this->actingAs($head)->postJson("/api/v1/workflow/requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_the_employees_supervisor_approves(): void
    {
        $this->actingAs($this->admin);
        $boss = $this->staff();
        $bossEmployee = Employee::create(['employee_no' => 'B1', 'fname' => 'Boss', 'lname' => 'One', 'gender' => 'Female', 'user_id' => $boss->id]);
        $nurse = $this->staff();
        $nurseEmployee = Employee::create(['employee_no' => 'N1', 'fname' => 'A', 'lname' => 'Nurse', 'gender' => 'Female', 'user_id' => $nurse->id]);
        \App\Models\EmpSupervisor::create(['employee_id' => $nurseEmployee->id, 'supervisor_id' => $bossEmployee->id, 'from_date' => now(), 'status' => 'active']);
        $other = $this->staff(['hod']);

        $this->configure([['name' => 'Supervisor', 'approver_type' => 'supervisor']]);
        $id = $this->requestRoles($nurse, ['hod']);

        $this->actingAs($other)->getJson('/api/v1/workflow/inbox')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($boss)->getJson('/api/v1/workflow/inbox')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($other)->postJson("/api/v1/workflow/requests/{$id}/approve")->assertUnprocessable();
        $this->actingAs($boss)->postJson("/api/v1/workflow/requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_supervisor_step_goes_to_the_head_of_department_when_none_is_assigned(): void
    {
        $this->actingAs($this->admin);
        $nursing = Department::create(['name' => 'Nursing']);
        $head = $this->staff();
        $headEmployee = Employee::create(['employee_no' => 'H1', 'fname' => 'Head', 'lname' => 'Nurse', 'gender' => 'Female', 'department_id' => $nursing->id, 'user_id' => $head->id]);
        HeadOfDepartment::create(['employee_id' => $headEmployee->id, 'department_id' => $nursing->id, 'level' => HeadOfDepartment::DEPARTMENT, 'is_active' => true]);
        $nurse = $this->staff();
        Employee::create(['employee_no' => 'N1', 'fname' => 'A', 'lname' => 'Nurse', 'gender' => 'Female', 'department_id' => $nursing->id, 'user_id' => $nurse->id]);

        $this->configure([['name' => 'Supervisor', 'approver_type' => 'supervisor']]);
        $id = $this->requestRoles($nurse, ['hod']);

        $this->actingAs($head)->postJson("/api/v1/workflow/requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_branch_workflow_overrides_the_company_one(): void
    {
        $hod = $this->staff(['hod']);
        $arushaManager = $this->staff();
        $this->configure([['name' => 'HOD', 'approver_type' => 'role', 'role_id' => $this->roleId('hod')]]);
        $this->configure([['name' => 'Arusha manager', 'approver_type' => 'user', 'user_id' => $arushaManager->id]], CompanyBranch::where('name', 'Arusha')->value('id'));

        $id = $this->requestRoles($this->staff(['employee'], 'Arusha'), ['hod']);

        $this->actingAs($hod)->getJson('/api/v1/workflow/inbox')->assertJsonCount(0, 'data');
        $this->actingAs($arushaManager)->postJson("/api/v1/workflow/requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_only_one_pending_change_per_user_and_requester_can_withdraw(): void
    {
        $this->configure([['name' => 'HOD', 'approver_type' => 'role', 'role_id' => $this->roleId('hod')]]);
        $target = $this->staff();
        $id = $this->requestRoles($target, ['hod']);

        $this->actingAs($this->admin)->postJson("/api/v1/admin/users/{$target->id}/deactivate", ['reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('user');

        $this->actingAs($this->admin)->getJson('/api/v1/workflow/my-requests')->assertJsonPath('data.0.can_cancel', true);
        $this->actingAs($this->admin)->postJson("/api/v1/workflow/requests/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('rejected', AccessChange::withoutCompanyScope()->latest('id')->value('status'));
    }

    public function test_editing_a_workflow_does_not_change_requests_in_progress(): void
    {
        $hod = $this->staff(['hod']);
        $someoneElse = $this->staff();
        $this->configure([['name' => 'HOD', 'approver_type' => 'role', 'role_id' => $this->roleId('hod')]]);
        $id = $this->requestRoles($this->staff(), ['hod']);

        $this->configure([['name' => 'Someone else', 'approver_type' => 'user', 'user_id' => $someoneElse->id]]);

        $this->actingAs($someoneElse)->getJson('/api/v1/workflow/inbox')->assertJsonCount(0, 'data');
        $this->actingAs($hod)->postJson("/api/v1/workflow/requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_outsiders_cannot_see_a_request(): void
    {
        $this->configure([['name' => 'HOD', 'approver_type' => 'role', 'role_id' => $this->roleId('hod')]]);
        $id = $this->requestRoles($this->staff(), ['hod']);

        $this->actingAs($this->staff())->getJson("/api/v1/workflow/requests/{$id}")->assertForbidden();
        [, $otherCompanyAdmin] = $this->createCompany('Beta');
        $this->actingAs($otherCompanyAdmin)->getJson("/api/v1/workflow/requests/{$id}")->assertNotFound();
    }
}
