<?php

namespace Modules\Appraisals\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmpSupervisor;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tasks\Models\Task;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class AppraisalTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private Department $nursing;

    private Department $ward;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Employee> */
    private array $staff = [];

    private array $competencies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-17 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'self-service'], ['slug' => 'tasks'], ['slug' => 'appraisals']]]);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->run($this->company, function () {
            $this->nursing = Department::create(['name' => 'Nursing']);
            $this->ward = Department::create(['name' => 'Ward A', 'parent_id' => $this->nursing->id]);
            $lab = Department::create(['name' => 'Laboratory']);
            foreach ([['matron', $this->nursing, null, 'employee'], ['sister', $this->nursing, $this->ward, 'employee'], ['asha', $this->nursing, $this->ward, 'employee'],
                ['neema', $this->nursing, $this->ward, 'employee'], ['juma', $lab, null, 'employee'], ['hr', $lab, null, 'hr-manager']] as [$who, $dept, $section, $role]) {
                $this->users[$who] = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole($role);
                $this->staff[$who] = Employee::create(['employee_no' => strtoupper($who), 'fname' => ucfirst($who), 'lname' => 'X', 'gender' => 'Female', 'department_id' => $dept->id,
                    'section_id' => $section?->id, 'user_id' => $this->users[$who]->id, 'employment_status' => 'confirmed', 'date_of_birth' => '1990-11-15']);
            }
            if (! WorkflowTemplate::where('process', 'appraisal')->exists()) {
                $t = WorkflowTemplate::create(['process' => 'appraisal', 'company_branch_id' => null, 'is_active' => true]);
                $t->steps()->create(['step_order' => 1, 'name' => 'Supervisor', 'approver_type' => 'supervisor', 'allow_send_back' => true]);
                $t->steps()->create(['step_order' => 2, 'name' => 'Head of department', 'approver_type' => 'department_head', 'allow_send_back' => true]);
                $t->steps()->create(['step_order' => 3, 'name' => 'HR moderation', 'approver_type' => 'role', 'role_id' => Role::where('name', 'hr-manager')->value('id'), 'allow_send_back' => true]);
            }
            Task::create(['title' => 'Stock count', 'employee_id' => $this->staff['asha']->id, 'department_id' => $this->nursing->id, 'status' => 'done', 'progress' => 100,
                'due_date' => '2026-03-10', 'completed_at' => '2026-03-09 10:00:00', 'priority' => 'normal']);
            Task::create(['title' => 'Audit', 'employee_id' => $this->staff['asha']->id, 'department_id' => $this->nursing->id, 'status' => 'in_progress', 'progress' => 50,
                'due_date' => '2026-06-30', 'priority' => 'normal']);
        });
        $this->giveActiveContracts($this->company);
        $this->actingAs($this->admin)->postJson("/api/v1/hr/structure/departments/{$this->nursing->id}/head", ['employee_id' => $this->staff['matron']->id])->assertOk();
        $this->postJson("/api/v1/hr/structure/departments/{$this->ward->id}/head", ['employee_id' => $this->staff['sister']->id])->assertOk();
        foreach ($this->users as $u) {
            $u->refresh();
        }

        $this->actingAs($this->users['hr']);
        $this->putJson('/api/v1/appraisal-settings', ['scale' => 'percent', 'mid_year' => true, 'parts' => [
            ['key' => 'tasks', 'label' => 'Tasks', 'weight' => 50, 'enabled' => true], ['key' => 'competencies', 'label' => 'Competencies', 'weight' => 40, 'enabled' => true],
            ['key' => 'attendance', 'label' => 'Attendance', 'weight' => 20, 'enabled' => true],
        ], 'bands' => [['min' => 80, 'label' => 'Very good'], ['min' => 0, 'label' => 'Needs improvement']]])->assertUnprocessable()->assertJsonValidationErrors(['parts' => 'add up to 100']);
        $this->putJson('/api/v1/appraisal-settings', ['scale' => 'percent', 'mid_year' => true, 'parts' => [
            ['key' => 'tasks', 'label' => 'Tasks', 'weight' => 50, 'enabled' => true], ['key' => 'competencies', 'label' => 'Competencies', 'weight' => 40, 'enabled' => true],
            ['key' => 'attendance', 'label' => 'Attendance', 'weight' => 10, 'enabled' => true],
        ], 'bands' => [['min' => 80, 'label' => 'Very good'], ['min' => 60, 'label' => 'Good'], ['min' => 0, 'label' => 'Needs improvement']]])->assertOk()->assertJsonPath('data.bands.0.min', 80);
        $this->competencies = [
            $this->postJson('/api/v1/appraisal-competencies', ['name' => 'Teamwork'])->assertCreated()->json('data.id'),
            $this->postJson('/api/v1/appraisal-competencies', ['name' => 'Communication'])->assertCreated()->json('data.id'),
        ];
    }

    private function openCycle(): int
    {
        $id = $this->actingAs($this->users['hr'])->postJson('/api/v1/appraisal-cycles', ['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'mid_year_date' => '2026-06-30'])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->postJson("/api/v1/appraisal-cycles/{$id}/open")->assertOk()->assertJsonPath('data.created', 7);
        $this->postJson("/api/v1/appraisal-cycles/{$id}/open")->assertOk()->assertJsonPath('data.created', 0);

        return $id;
    }

    private function scores(int $tasks, array $competencies, int $attendance): array
    {
        return ['tasks' => $tasks, 'attendance' => $attendance, 'competencies' => array_combine($this->competencies, $competencies)];
    }

    public function test_self_assessment_supervisor_who_is_hod_hr_moderation_and_acknowledgement(): void
    {
        $cycle = $this->openCycle();
        $this->actingAs($this->users['asha'])->getJson('/api/v1/appraisals')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'open');
        $id = $this->getJson('/api/v1/appraisals')->json('data.0.id');
        $this->getJson("/api/v1/appraisals/{$id}")->assertJsonPath('data.can.self', true)->assertJsonCount(2, 'data.competencies')
            ->assertJsonPath('data.tasks.total', 2)->assertJsonPath('data.tasks.on_time', 1)->assertJsonPath('data.tasks.average_progress', 75);

        $this->postJson("/api/v1/appraisals/{$id}/self", ['scores' => ['tasks' => 90], 'comment' => 'Draft', 'submit' => false])->assertOk()->assertJsonPath('data.status', 'open');
        $this->postJson("/api/v1/appraisals/{$id}/self", ['scores' => ['tasks' => 90], 'submit' => true])->assertUnprocessable()->assertJsonValidationErrors(['scores' => 'every part']);
        $this->postJson("/api/v1/appraisals/{$id}/self", ['scores' => $this->scores(120, [90, 90], 90), 'submit' => true])->assertUnprocessable()->assertJsonValidationErrors(['scores' => '0 to 100']);
        $this->postJson("/api/v1/appraisals/{$id}/self", ['scores' => $this->scores(95, [90, 80], 100), 'comment' => 'Good year', 'submit' => true])->assertOk()->assertJsonPath('data.status', 'review');

        $this->actingAs($this->users['juma'])->getJson("/api/v1/appraisals/{$id}")->assertNotFound();
        $this->actingAs($this->users['sister'])->getJson("/api/v1/appraisals/{$id}")->assertOk()->assertJsonPath('data.can.review', false);
        $this->actingAs($this->users['matron'])->getJson('/api/v1/appraisals?view=team&filter[status]=awaiting_me')->assertJsonCount(1, 'data')->assertJsonPath('data.0.awaiting_me', true);
        $this->getJson("/api/v1/appraisals/{$id}")->assertJsonPath('data.my_rater', 'supervisor')->assertJsonPath('data.waiting_for', 'Supervisor');
        $this->postJson("/api/v1/appraisals/{$id}/review", ['scores' => $this->scores(80, [70, 70], 90), 'decision' => 'approve'])->assertUnprocessable();
        $this->postJson("/api/v1/appraisals/{$id}/review", ['scores' => $this->scores(80, [70, 70], 90), 'comment' => 'Solid work', 'decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'review');
        $this->getJson("/api/v1/appraisals/{$id}")->assertJsonPath('data.waiting_for', 'HR moderation')->assertJsonPath('data.can.review', false);

        $this->actingAs($this->users['asha'])->getJson("/api/v1/appraisals/{$id}")->assertJsonCount(1, 'data.scores')->assertJsonPath('data.final_score', null)->assertJsonPath('data.can.withdraw', false);

        $this->actingAs($this->users['hr'])->getJson("/api/v1/appraisals/{$id}")->assertJsonPath('data.my_rater', 'hr')->assertJsonPath('data.scores.1.total', 77);
        $this->postJson("/api/v1/appraisals/{$id}/review", ['scores' => $this->scores(80, [80, 80], 90), 'decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'acknowledge');

        $this->actingAs($this->users['asha'])->getJson("/api/v1/appraisals/{$id}")->assertJsonPath('data.final_score', 81)->assertJsonPath('data.rating', 'Very good')
            ->assertJsonCount(3, 'data.scores')->assertJsonPath('data.can.acknowledge', true);
        $this->postJson("/api/v1/appraisals/{$id}/acknowledge", ['agrees' => false])->assertUnprocessable();
        $this->postJson("/api/v1/appraisals/{$id}/acknowledge", ['agrees' => true, 'comment' => 'Thank you'])->assertOk()->assertJsonPath('data.status', 'done');
        $this->get("/api/v1/appraisals/{$id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->actingAs($this->users['hr'])->getJson("/api/v1/appraisal-cycles/{$cycle}")->assertOk()->assertJsonPath('data.done', 1)->assertJsonPath('ratings.0.label', 'Very good');
        $this->actingAs($this->users['matron'])->getJson("/api/v1/appraisal-cycles/{$cycle}")->assertForbidden();
    }

    public function test_assigned_supervisor_rates_then_hod_and_return_to_employee(): void
    {
        EmpSupervisor::create(['employee_id' => $this->staff['neema']->id, 'supervisor_id' => $this->staff['sister']->id, 'from_date' => '2026-01-01', 'status' => 'active']);
        $this->openCycle();
        $id = $this->actingAs($this->users['neema'])->getJson('/api/v1/appraisals')->json('data.0.id');

        $this->actingAs($this->users['sister'])->postJson("/api/v1/appraisals/{$id}/mid-year", ['comment' => 'On track'])->assertOk();
        $this->actingAs($this->users['juma'])->postJson("/api/v1/appraisals/{$id}/mid-year", ['comment' => 'x'])->assertNotFound();

        $this->actingAs($this->users['neema'])->getJson("/api/v1/appraisals/{$id}")->assertJsonPath('data.mid_year_comment', 'On track');
        $this->postJson("/api/v1/appraisals/{$id}/self", ['scores' => $this->scores(70, [70, 70], 70), 'submit' => true])->assertOk();
        $this->postJson("/api/v1/appraisals/{$id}/withdraw")->assertOk()->assertJsonPath('data.status', 'open');
        $this->postJson("/api/v1/appraisals/{$id}/self", ['scores' => $this->scores(70, [70, 70], 70), 'submit' => true])->assertOk();

        $this->actingAs($this->users['matron'])->getJson("/api/v1/appraisals/{$id}")->assertJsonPath('data.can.review', false);
        $this->actingAs($this->users['sister'])->postJson("/api/v1/appraisals/{$id}/review", ['scores' => $this->scores(60, [60, 60], 60), 'comment' => 'Fair', 'decision' => 'approve'])->assertOk();
        $this->actingAs($this->users['neema'])->postJson("/api/v1/appraisals/{$id}/withdraw")->assertUnprocessable();

        $this->actingAs($this->users['matron'])->getJson("/api/v1/appraisals/{$id}")->assertJsonPath('data.my_rater', 'hod');
        $this->postJson("/api/v1/appraisals/{$id}/review", ['decision' => 'return'])->assertUnprocessable()->assertJsonValidationErrors(['comment']);
        $this->postJson("/api/v1/appraisals/{$id}/review", ['decision' => 'return', 'comment' => 'Add evidence for tasks'])->assertOk()->assertJsonPath('data.status', 'returned');

        $this->actingAs($this->users['neema'])->getJson("/api/v1/appraisals/{$id}")->assertJsonPath('data.decision_comment', 'Add evidence for tasks')->assertJsonPath('data.can.self', true);
        $this->postJson("/api/v1/appraisals/{$id}/self", ['scores' => $this->scores(75, [70, 70], 70), 'submit' => true])->assertOk()->assertJsonPath('data.status', 'review');
        $this->actingAs($this->users['sister'])->getJson("/api/v1/appraisals/{$id}")->assertJsonPath('data.my_rater', 'supervisor');
    }
}
