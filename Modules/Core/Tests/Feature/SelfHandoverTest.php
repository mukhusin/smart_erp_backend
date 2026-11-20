<?php

namespace Modules\Core\Tests\Feature;

use App\Models\Department;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\LeaveType;
use App\Models\NatureOfContract;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\RoleHandover;
use Modules\Core\Services\AccessService;
use Modules\Hr\Actions\ManageLeave;
use Modules\Workflow\Models\WorkflowTemplate;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class SelfHandoverTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private User $head;

    private User $deputy;

    private User $hr;

    private Employee $headEmployee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-16 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => array_map(fn ($s) => ['slug' => $s], ['hr', 'leave', 'self-service', 'admin'])]);
        setPermissionsTeamId($this->company->id);
        $this->inCompany(function () {
            $ward = Department::create(['name' => 'Ward']);
            $stc = NatureOfContract::create(['contract_name' => 'Specific time', 'code' => 'STC', 'employment_type' => 'full_time', 'has_allowance' => true, 'has_annual_leave' => true]);
            $make = function (string $no, array $roles) use ($ward, $stc) {
                $u = User::factory()->create(['company_id' => $this->company->id]);
                $u->assignRole($roles);
                $e = Employee::create(['employee_no' => $no, 'fname' => $no, 'lname' => 'X', 'gender' => 'Female', 'department_id' => $ward->id, 'user_id' => $u->id,
                    'emp_type' => 'full_time', 'employment_status' => 'confirmed', 'has_annual_leave' => true, 'employment_date' => '2020-01-01']);
                EmployeeContract::create(['employee_id' => $e->id, 'nature_of_contract_id' => $stc->id, 'contract_no' => $no, 'basic_salary' => 900000, 'period' => 12,
                    'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'active', 'is_active' => true]);

                return [$u, $e];
            };
            [$this->head, $this->headEmployee] = $make('Head', ['hod', 'employee']);
            [$this->deputy] = $make('Deputy', ['employee']);
            [$this->hr] = $make('Hr', ['hr-officer', 'employee']);
            WorkflowTemplate::whereIn('process', ['leave-request', 'role-handover'])->delete();
        });
    }

    private function inCompany(callable $fn): mixed
    {
        return app(CompanyContext::class)->run($this->company, $fn);
    }

    private function can(User $user, string $permission): bool
    {
        $access = app(AccessService::class);
        $access->forget();

        return $access->can($user->fresh(), $permission);
    }

    public function test_an_employee_hands_over_a_role_for_approval_and_is_suspended_meanwhile(): void
    {
        $this->inCompany(fn () => WorkflowTemplate::create(['process' => 'role-handover', 'is_active' => true])->steps()->create(['step_order' => 1, 'name' => 'HR', 'approver_type' => 'user', 'user_id' => $this->hr->id]));
        $roles = $this->actingAs($this->head)->getJson('/api/v1/self/handovers')->assertOk()->json('meta.roles');
        $this->assertSame(['hod'], array_column($roles, 'name'), 'the plain employee role is not offered');
        $this->assertContains($this->deputy->id, array_column($this->getJson('/api/v1/self/handovers/colleagues')->json('data'), 'id'));
        $adminRole = $this->inCompany(fn () => \Spatie\Permission\Models\Role::where('name', 'company-admin')->value('id'));
        $this->postJson('/api/v1/self/handovers', ['role_id' => $adminRole, 'to_user_id' => $this->deputy->id, 'starts_at' => '2026-11-16', 'ends_at' => '2026-11-20', 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['role_id']);

        $id = $this->postJson('/api/v1/self/handovers', ['role_id' => $roles[0]['id'], 'to_user_id' => $this->deputy->id, 'starts_at' => '2026-11-16', 'ends_at' => '2026-11-20', 'reason' => 'Workshop'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->assertFalse($this->can($this->deputy, 'leave.approve-hod'), 'not before approval');
        $this->assertTrue($this->can($this->head, 'leave.approve-hod'));

        $wf = $this->inCompany(fn () => RoleHandover::find($id)->workflow);
        $this->actingAs($this->hr)->postJson("/api/v1/workflow/requests/{$wf->id}/approve")->assertOk();
        $this->assertTrue($this->can($this->deputy, 'leave.approve-hod'));
        $this->assertFalse($this->can($this->head, 'leave.approve-hod'), 'the giver is suspended');
        $this->actingAs($this->deputy)->getJson('/api/v1/me/notifications')->assertJsonPath('data.0.title', 'You are covering a role');
        $this->getJson('/api/v1/self/handovers')->assertJsonPath('data.0.direction', 'received')->assertJsonPath('data.0.can_cancel', false);

        $this->travelTo('2026-11-21 08:00:00');
        $this->assertFalse($this->can($this->deputy, 'leave.approve-hod'), 'ends by itself');
        $this->assertTrue($this->can($this->head, 'leave.approve-hod'));
    }

    public function test_the_giver_can_end_it_early(): void
    {
        $role = $this->actingAs($this->head)->getJson('/api/v1/self/handovers')->json('meta.roles.0.id');
        $id = $this->postJson('/api/v1/self/handovers', ['role_id' => $role, 'to_user_id' => $this->deputy->id, 'starts_at' => '2026-11-16', 'ends_at' => '2026-11-30', 'reason' => 'Trip'])
            ->assertCreated()->assertJsonPath('data.status', 'approved')->json('data.id');
        $this->assertTrue($this->can($this->deputy, 'leave.approve-hod'));
        $this->actingAs($this->deputy)->postJson("/api/v1/self/handovers/{$id}/cancel")->assertNotFound();
        $this->actingAs($this->head)->postJson("/api/v1/self/handovers/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'ended');
        $this->assertFalse($this->can($this->deputy, 'leave.approve-hod'));
        $this->assertTrue($this->can($this->head, 'leave.approve-hod'));
    }

    public function test_a_handover_with_leave_follows_the_leave(): void
    {
        $annual = $this->inCompany(fn () => LeaveType::where('code', 'AL')->value('id'));
        $leave = $this->inCompany(fn () => app(ManageLeave::class)->request($this->headEmployee, ['leave_type_id' => $annual, 'start_date' => '2026-11-23', 'days' => 3, 'handover_to_user_id' => $this->deputy->id], $this->head, 'hr'));
        $this->assertSame(EmpLeave::APPROVED, $leave->status);
        $this->assertFalse($this->can($this->deputy, 'leave.approve-hod'), 'not before the leave starts');
        $this->actingAs($this->head)->getJson('/api/v1/self/handovers')->assertJsonPath('data.0.status', 'upcoming')->assertJsonPath('data.0.can_cancel', false);

        $this->travelTo('2026-11-24 10:00:00');
        $this->assertTrue($this->can($this->deputy, 'leave.approve-hod'));
        $this->assertFalse($this->can($this->head, 'leave.approve-hod'));
        $this->inCompany(fn () => app(ManageLeave::class)->recordReturn($leave->refresh(), '2026-11-25', $this->hr, null));
        $this->travelTo('2026-11-25 09:00:00');
        $this->assertFalse($this->can($this->deputy, 'leave.approve-hod'), 'ends when the employee comes back early');
        $this->assertTrue($this->can($this->head, 'leave.approve-hod'));
    }

    public function test_a_leave_not_approved_cancels_its_handover(): void
    {
        $this->inCompany(fn () => WorkflowTemplate::create(['process' => 'leave-request', 'is_active' => true])->steps()->create(['step_order' => 1, 'name' => 'HR', 'approver_type' => 'user', 'user_id' => $this->hr->id]));
        $annual = $this->inCompany(fn () => LeaveType::where('code', 'AL')->value('id'));
        $leave = $this->inCompany(fn () => app(ManageLeave::class)->request($this->headEmployee, ['leave_type_id' => $annual, 'start_date' => '2026-11-23', 'days' => 3, 'handover_to_user_id' => $this->deputy->id], $this->head, 'hr'));
        $this->assertSame('pending', $this->inCompany(fn () => RoleHandover::where('emp_leave_id', $leave->id)->value('status')));
        $this->actingAs($this->hr)->postJson("/api/v1/workflow/requests/{$leave->workflow->id}/reject", ['comment' => 'Busy month'])->assertOk();
        $this->assertSame('cancelled', $this->inCompany(fn () => RoleHandover::where('emp_leave_id', $leave->id)->value('status')));
    }
}
