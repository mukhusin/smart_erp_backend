<?php

namespace Modules\Planning\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class PlanningTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private Department $nursing;

    private Department $lab;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Employee> */
    private array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-17 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'self-service'], ['slug' => 'tasks'], ['slug' => 'planning']]]);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->run($this->company, function () {
            $this->nursing = Department::create(['name' => 'Nursing', 'is_active' => true]);
            Department::create(['name' => 'Ward A', 'parent_id' => $this->nursing->id, 'is_active' => true]);
            $this->lab = Department::create(['name' => 'Laboratory', 'is_active' => true]);
            foreach ([['matron', $this->nursing, 'employee'], ['asha', $this->nursing, 'employee'], ['juma', $this->lab, 'employee'], ['dg', $this->lab, 'director-general']] as [$who, $dept, $role]) {
                $this->users[$who] = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole($role);
                $this->staff[$who] = Employee::create(['employee_no' => strtoupper($who), 'fname' => ucfirst($who), 'lname' => 'X', 'gender' => 'Female', 'department_id' => $dept->id,
                    'user_id' => $this->users[$who]->id, 'employment_status' => 'confirmed', 'date_of_birth' => '1990-11-15']);
            }
            if (! WorkflowTemplate::where('process', 'department-plan')->exists()) {
                $t = WorkflowTemplate::create(['process' => 'department-plan', 'company_branch_id' => null, 'is_active' => true]);
                $t->steps()->create(['step_order' => 1, 'name' => 'Director general', 'approver_type' => 'role', 'role_id' => Role::where('name', 'director-general')->value('id'), 'allow_send_back' => false]);
            }
        });
        $this->giveActiveContracts($this->company);
        $this->actingAs($this->admin)->postJson("/api/v1/hr/structure/departments/{$this->nursing->id}/head", ['employee_id' => $this->staff['matron']->id])->assertOk();
        foreach ($this->users as $u) {
            $u->refresh();
        }
    }

    public function test_strategic_and_annual_plan_department_activities_approved_then_used_by_tasks(): void
    {
        $this->actingAs($this->users['matron'])->postJson('/api/v1/planning/strategic-plans', ['title' => 'X', 'start_year' => 2026, 'end_year' => 2030])->assertForbidden();
        $sp = $this->actingAs($this->admin)->postJson('/api/v1/planning/strategic-plans', ['title' => 'Strategic plan 2026–2030', 'start_year' => 2026, 'end_year' => 2030, 'vision' => 'Best care'])
            ->assertCreated()->json('data.id');
        $this->putJson("/api/v1/planning/strategic-plans/{$sp}", ['title' => 'Strategic plan 2026–2030', 'start_year' => 2026, 'end_year' => 2030, 'status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');
        $objective = $this->postJson("/api/v1/planning/strategic-plans/{$sp}/objectives", ['code' => 'SO1', 'title' => 'Reduce infections'])->assertCreated()->json('data.id');

        $defaults = $this->getJson('/api/v1/planning/annual-plans')->assertOk()->json('defaults');
        $this->assertSame(['year' => 2026, 'title' => 'Annual plan 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], $defaults);
        $plan = $this->postJson('/api/v1/planning/annual-plans', $defaults + ['strategic_plan_id' => $sp])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/planning/annual-plans/{$plan}/open")->assertOk()->assertJsonPath('data.created', 3);

        $this->actingAs($this->users['matron'])->getJson("/api/v1/planning/annual-plans/{$plan}")->assertOk()->assertJsonCount(1, 'departments')
            ->assertJsonPath('departments.0.name', 'Nursing')->assertJsonPath('departments.0.can_plan', true);
        $this->getJson("/api/v1/planning/annual-plans/{$plan}/departments/{$this->lab->id}")->assertForbidden();
        $base = "/api/v1/planning/annual-plans/{$plan}/departments/{$this->nursing->id}";
        $this->postJson("{$base}/submit")->assertUnprocessable()->assertJsonValidationErrors(['plan' => 'Add the department']);
        $activity = $this->postJson("{$base}/activities", ['code' => '1.1', 'title' => 'Hand hygiene audits', 'output' => 'Monthly audit reports', 'target' => '12 audits',
            'kpi' => 'Compliance ≥ 90%', 'quarters' => '1,2,3,4', 'strategic_objective_id' => $objective])->assertCreated()->json('data.id');
        $this->getJson($base)->assertJsonPath('activities.0.objective.label', 'SO1 Reduce infections')->assertJsonPath('activities.0.is_active', false)->assertJsonPath('can.submit', true);

        $this->actingAs($this->users['asha'])->postJson('/api/v1/tasks', ['title' => 'Audit ward A', 'task_activity_id' => $activity])->assertUnprocessable()->assertJsonValidationErrors(['task_activity_id']);
        $this->actingAs($this->users['matron'])->postJson('/api/v1/task-activities', ['title' => 'Side activity', 'year' => 2026, 'department_id' => $this->nursing->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['activity' => 'come from the annual plan']);

        $this->postJson("{$base}/submit")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->putJson("{$base}/activities/{$activity}", ['title' => 'Changed'])->assertUnprocessable();
        $workflow = WorkflowInstance::where('process', 'department-plan')->value('id');
        $this->actingAs($this->users['dg'])->postJson("/api/v1/workflow/requests/{$workflow}/approve")->assertOk();

        $this->actingAs($this->users['matron'])->getJson($base)->assertJsonPath('data.status', 'approved')->assertJsonPath('activities.0.is_active', true)->assertJsonPath('can.edit', false);
        $this->postJson('/api/v1/tasks', ['title' => 'Audit ward A', 'task_activity_id' => $activity, 'employee_ids' => [$this->staff['asha']->id]])->assertCreated();
        $this->putJson("/api/v1/task-activities/{$activity}", ['title' => 'X'])->assertUnprocessable();
        $this->getJson('/api/v1/task-activities?year=2026')->assertJsonPath('data.0.planned', true)->assertJsonPath('data.0.can_manage', false)->assertJsonPath('data.0.target', '12 audits');

        $this->actingAs($this->admin)->getJson("/api/v1/planning/strategic-plans/{$sp}")->assertJsonPath('objectives.0.activities', 1)->assertJsonPath('years.0.year', 2026);
        $this->getJson("/api/v1/planning/annual-plans/{$plan}")->assertJsonCount(3, 'departments')->assertJsonPath('data.approved', 1);
        $this->postJson("{$base}/reopen")->assertOk()->assertJsonPath('data.status', 'draft');
        $this->actingAs($this->users['matron'])->deleteJson("{$base}/activities/{$activity}")->assertUnprocessable()->assertJsonValidationErrors(['activity' => 'has tasks']);
    }

    public function test_returned_plan_and_withdraw(): void
    {
        $plan = $this->actingAs($this->admin)->postJson('/api/v1/planning/annual-plans', ['title' => 'Annual plan 2027', 'year' => 2027, 'start_date' => '2027-01-01', 'end_date' => '2027-12-31'])->json('data.id');
        $this->postJson("/api/v1/planning/annual-plans/{$plan}/open")->assertOk();
        $base = "/api/v1/planning/annual-plans/{$plan}/departments/{$this->nursing->id}";
        $this->actingAs($this->users['matron'])->postJson("{$base}/activities", ['title' => 'Training', 'quarters' => '5'])->assertUnprocessable()->assertJsonValidationErrors(['quarters']);
        $this->postJson("{$base}/activities", ['title' => 'Training'])->assertCreated();
        $this->postJson("{$base}/submit")->assertOk();
        $this->postJson("{$base}/withdraw")->assertOk()->assertJsonPath('data.status', 'draft');
        $this->postJson("{$base}/submit")->assertOk();
        $workflow = WorkflowInstance::where('process', 'department-plan')->latest('id')->value('id');
        $this->actingAs($this->users['dg'])->postJson("/api/v1/workflow/requests/{$workflow}/reject", ['comment' => 'Add targets'])->assertOk();
        $this->actingAs($this->users['matron'])->getJson($base)->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_comment', 'Add targets')->assertJsonPath('can.edit', true);
        $this->assertTrue($this->users['matron']->notifications()->where('data->title', 'Department plan returned')->exists());
    }
}
