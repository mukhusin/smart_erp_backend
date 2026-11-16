<?php

namespace Modules\Tasks\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tasks\Models\Task;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowTemplate;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private Department $nursing;

    private Department $ward;

    private Department $lab;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Employee> */
    private array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-16 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'self-service'], ['slug' => 'tasks']]]);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->run($this->company, function () {
            $this->nursing = Department::create(['name' => 'Nursing']);
            $this->ward = Department::create(['name' => 'Ward A', 'parent_id' => $this->nursing->id]);
            $this->lab = Department::create(['name' => 'Laboratory']);
            foreach ([['matron', $this->nursing, null], ['sister', $this->nursing, $this->ward], ['asha', $this->nursing, $this->ward], ['neema', $this->nursing, $this->ward], ['juma', $this->lab, null]] as [$who, $dept, $section]) {
                $this->users[$who] = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
                $this->staff[$who] = Employee::create(['employee_no' => strtoupper($who), 'fname' => ucfirst($who), 'lname' => 'X', 'gender' => 'Female', 'department_id' => $dept->id,
                    'section_id' => $section?->id, 'user_id' => $this->users[$who]->id, 'employment_status' => 'confirmed', 'date_of_birth' => '1990-11-15']);
            }
            if (! WorkflowTemplate::where('process', 'task-progress')->exists()) {
                $t = WorkflowTemplate::create(['process' => 'task-progress', 'company_branch_id' => null, 'is_active' => true]);
                $t->steps()->create(['step_order' => 1, 'name' => 'Supervisor', 'approver_type' => 'supervisor', 'allow_send_back' => false]);
            }
        });
        $this->giveActiveContracts($this->company);
        $this->actingAs($this->admin)->postJson("/api/v1/hr/structure/departments/{$this->nursing->id}/head", ['employee_id' => $this->staff['matron']->id])->assertOk();
        $this->postJson("/api/v1/hr/structure/departments/{$this->ward->id}/head", ['employee_id' => $this->staff['sister']->id])->assertOk();
        foreach ($this->users as $u) {
            $u->refresh();
        }
    }

    public function test_head_assigns_staff_see_only_their_own_and_finishing_goes_to_review(): void
    {
        $this->actingAs($this->users['asha'])->postJson('/api/v1/tasks', ['title' => 'Bad', 'employee_ids' => [$this->staff['neema']->id]])->assertUnprocessable();
        $this->actingAs($this->users['sister'])->postJson('/api/v1/tasks', ['title' => 'Lab', 'employee_ids' => [$this->staff['juma']->id]])->assertUnprocessable();

        $ids = $this->postJson('/api/v1/tasks', ['title' => 'Stock count', 'priority' => 'high', 'due_date' => '2026-11-20', 'employee_ids' => [$this->staff['asha']->id, $this->staff['neema']->id]])
            ->assertCreated()->assertJsonCount(2, 'data')->json('data.*.id');
        $this->assertSame(1, $this->users['asha']->notifications()->count());

        $this->getJson('/api/v1/tasks?view=team')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($this->users['matron'])->getJson('/api/v1/tasks?view=team')->assertJsonCount(2, 'data');
        $this->actingAs($this->users['asha'])->getJson('/api/v1/tasks')->assertJsonCount(1, 'data')->assertJsonPath('data.0.can.start', true)->assertJsonPath('data.0.can.manage', false);
        $this->getJson('/api/v1/tasks?view=team')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/tasks/{$ids[1]}")->assertNotFound();
        $this->actingAs($this->users['juma'])->getJson("/api/v1/tasks/{$ids[0]}")->assertNotFound();

        $this->actingAs($this->users['asha'])->postJson("/api/v1/tasks/{$ids[0]}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->postJson("/api/v1/tasks/{$ids[0]}/cancel")->assertForbidden();
        $this->postJson("/api/v1/tasks/{$ids[0]}/complete", ['comment' => 'Counted'])->assertOk()->assertJsonPath('data.status', 'review');

        $this->actingAs($this->users['sister'])->postJson("/api/v1/tasks/{$ids[0]}/review", ['accept' => false])->assertUnprocessable();
        $this->postJson("/api/v1/tasks/{$ids[0]}/review", ['accept' => false, 'comment' => 'Count ward B too'])->assertOk()->assertJsonPath('data.status', 'returned');
        $this->actingAs($this->users['asha'])->postJson("/api/v1/tasks/{$ids[0]}/complete")->assertOk();
        $this->actingAs($this->users['matron'])->postJson("/api/v1/tasks/{$ids[0]}/review", ['accept' => true])->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.progress', 100);

        $this->actingAs($this->users['asha'])->getJson('/api/v1/tasks/summary')->assertJsonPath('data.mine.open', 0)->assertJsonPath('data.mine.done_month', 1)->assertJsonPath('data.team', null);
    }

    public function test_progress_counts_once_the_supervisor_approves_and_is_capped_per_update(): void
    {
        $id = $this->actingAs($this->users['asha'])->postJson('/api/v1/tasks', ['title' => 'Own task'])->assertCreated()->json('data.0.id');
        $this->postJson("/api/v1/tasks/{$id}/updates", ['note' => 'Too much', 'progress' => 50])->assertUnprocessable()->assertJsonValidationErrors(['progress' => 'at most 30%']);
        $this->postJson("/api/v1/tasks/{$id}/updates", ['note' => 'Started', 'progress' => 25])->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->postJson("/api/v1/tasks/{$id}/updates", ['note' => 'More', 'progress' => 40])->assertUnprocessable()->assertJsonValidationErrors(['progress' => 'still waiting']);
        $this->getJson("/api/v1/tasks/{$id}")->assertJsonPath('data.progress', 0)->assertJsonPath('data.status', 'in_progress')->assertJsonPath('updates.0.can_withdraw', true);

        $workflow = WorkflowInstance::where('process', 'task-progress')->value('id');
        $this->actingAs($this->users['neema'])->postJson("/api/v1/workflow/requests/{$workflow}/approve")->assertUnprocessable();
        $this->actingAs($this->users['sister'])->getJson('/api/v1/tasks/summary')->assertJsonPath('data.team.pending_updates', 1);
        $this->postJson("/api/v1/workflow/requests/{$workflow}/approve")->assertUnprocessable();
        $this->actingAs($this->users['matron'])->postJson("/api/v1/workflow/requests/{$workflow}/approve")->assertOk();
        $this->actingAs($this->users['asha'])->getJson("/api/v1/tasks/{$id}")->assertJsonPath('data.progress', 25)->assertJsonPath('updates.0.status', 'approved');

        $this->postJson("/api/v1/tasks/{$id}/updates", ['note' => 'Back', 'progress' => 20])->assertUnprocessable();
        $this->postJson("/api/v1/tasks/{$id}/updates", ['note' => 'Half', 'progress' => 50])->assertCreated();
        $second = WorkflowInstance::where('process', 'task-progress')->latest('id')->value('id');
        $this->actingAs($this->users['matron'])->postJson("/api/v1/workflow/requests/{$second}/reject", ['comment' => 'Not yet'])->assertOk();
        $this->actingAs($this->users['asha'])->getJson("/api/v1/tasks/{$id}")->assertJsonPath('data.progress', 25)->assertJsonPath('updates.0.status', 'rejected');

        $this->postJson("/api/v1/tasks/{$id}/complete")->assertOk()->assertJsonPath('data.status', 'done');
    }

    public function test_activities_comments_subtasks_and_recurring_tasks(): void
    {
        $this->actingAs($this->users['asha'])->postJson('/api/v1/task-activities', ['title' => 'X', 'year' => 2026, 'department_id' => $this->nursing->id])->assertForbidden();
        $this->actingAs($this->users['matron'])->postJson('/api/v1/task-activities', ['title' => 'Lab work', 'year' => 2026, 'department_id' => $this->lab->id])->assertForbidden();
        $activity = $this->postJson('/api/v1/task-activities', ['title' => 'Infection control', 'year' => 2026, 'department_id' => $this->nursing->id])->assertCreated()->json('data.id');

        $parent = $this->postJson('/api/v1/tasks', ['title' => 'Hand hygiene audit', 'task_activity_id' => $activity, 'due_date' => '2026-11-16', 'recurrence' => 'weekly',
            'employee_ids' => [$this->staff['asha']->id], 'checklist' => [['text' => 'Ward A'], ['text' => 'Ward B']]])->assertCreated()->json('data.0.id');
        $this->actingAs($this->users['asha'])->postJson('/api/v1/tasks', ['title' => 'Ward A part', 'parent_id' => $parent])->assertCreated();
        $this->getJson("/api/v1/tasks/{$parent}")->assertJsonCount(1, 'subtasks')->assertJsonPath('data.activity.title', 'Infection control');
        $this->putJson("/api/v1/tasks/{$parent}/checklist", ['checklist' => [['text' => 'Ward A', 'done' => true], ['text' => 'Ward B']]])->assertOk()->assertJsonPath('data.checklist.0.done', true);
        $this->putJson("/api/v1/tasks/{$parent}", ['title' => 'Mine now'])->assertForbidden();

        $this->postJson("/api/v1/tasks/{$parent}/comments", ['body' => 'Ward B is closed today'])->assertCreated();
        $this->assertTrue($this->users['matron']->notifications()->where('data->title', 'Comment on task')->exists());

        $this->actingAs($this->users['matron'])->getJson('/api/v1/task-activities?year=2026')->assertJsonPath('data.0.tasks', 2)->assertJsonPath('data.0.can_manage', true);
        $this->deleteJson("/api/v1/task-activities/{$activity}")->assertUnprocessable();

        $this->travelTo('2026-11-17 06:00:00');
        $this->artisan('tasks:daily')->assertSuccessful();
        $next = Task::withoutGlobalScopes()->where('title', 'Hand hygiene audit')->where('id', '!=', $parent)->first();
        $this->assertSame('2026-11-23', $next->due_date->toDateString());
        $this->assertFalse($next->checklist[0]['done']);
        $this->artisan('tasks:daily')->assertSuccessful();
        $this->assertSame(2, Task::withoutGlobalScopes()->where('title', 'Hand hygiene audit')->count());
        $this->assertTrue($this->users['asha']->notifications()->where('data->title', 'Task overdue')->exists());
    }
}
