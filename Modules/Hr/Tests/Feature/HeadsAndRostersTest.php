<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeTimetable;
use App\Models\RosterCode;
use App\Models\RosterChangeRequest;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Workflow\Models\WorkflowInstance;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class HeadsAndRostersTest extends TestCase
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

    private int $morning;

    private int $off;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-12 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'roster'], ['slug' => 'admin'], ['slug' => 'self-service'], ['slug' => 'overtime'], ['slug' => 'reports']]]);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->run($this->company, function () {
            $this->nursing = Department::create(['name' => 'Nursing']);
            $this->ward = Department::create(['name' => 'Ward A', 'parent_id' => $this->nursing->id]);
            foreach ([['matron', null], ['deputy', null], ['sister', $this->ward], ['asha', $this->ward], ['neema', $this->ward]] as [$who, $section]) {
                $this->users[$who] = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
                $this->staff[$who] = Employee::create(['employee_no' => strtoupper($who), 'fname' => ucfirst($who), 'lname' => 'X', 'gender' => 'Female', 'department_id' => $this->nursing->id,
                    'section_id' => $section?->id, 'user_id' => $this->users[$who]->id, 'employment_status' => 'confirmed', 'date_of_birth' => '1990-11-15']);
            }
            $this->morning = RosterCode::create(['code' => 'M', 'duty' => 'Morning', 'can_punch' => true, 'start_time' => '07:30', 'end_time' => '15:30', 'work_hour' => 8])->id;
            $this->off = RosterCode::create(['code' => 'O', 'duty' => 'Off', 'can_punch' => false])->id;
        });
        $this->giveActiveContracts($this->company);
        $this->actingAs($this->admin)->postJson("/api/v1/hr/structure/departments/{$this->nursing->id}/head", ['employee_id' => $this->staff['matron']->id])->assertOk();
        $this->postJson("/api/v1/hr/structure/departments/{$this->ward->id}/head", ['employee_id' => $this->staff['sister']->id])->assertOk();
        $this->postJson("/api/v1/hr/structure/departments/{$this->nursing->id}/deputies", ['employee_id' => $this->staff['deputy']->id])->assertOk()
            ->assertJsonPath('data.deputies.0.name', 'Deputy X')->assertJsonPath('data.head.name', 'Matron X');
        foreach ($this->users as $u) {
            $u->refresh();
        }
    }

    private function duties(int $roster, array $rows, User $as, ?string $reason = null)
    {
        return $this->actingAs($as)->putJson("/api/v1/roster/rosters/{$roster}/duties", ['reason' => $reason, 'duties' => collect($rows)->map(fn ($r) => [
            'employee_id' => $this->staff[$r[0]]->id, 'date' => $r[1], 'roster_code_id' => $r[2],
        ])->all()]);
    }

    public function test_section_head_prepares_and_the_head_of_department_or_deputy_approves(): void
    {
        $this->actingAs($this->users['sister'])->postJson('/api/v1/roster/rosters', ['department_id' => $this->nursing->id, 'from_date' => '2026-11-16', 'to_date' => '2026-11-22'])->assertForbidden();
        $id = $this->postJson('/api/v1/roster/rosters', ['department_id' => $this->ward->id, 'from_date' => '2026-11-16', 'to_date' => '2026-11-22'])->assertCreated()->json('data.id');
        $this->duties($id, [['asha', '2026-11-16', $this->morning], ['neema', '2026-11-16', $this->off]], $this->users['sister'])->assertOk()->assertJsonPath('data.saved', 2);
        $this->postJson("/api/v1/roster/rosters/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.waiting_for', 'Head of department');

        $workflow = WorkflowInstance::where('process', 'duty-roster')->value('id');
        $this->actingAs($this->users['sister'])->postJson("/api/v1/workflow/requests/{$workflow}/approve")->assertUnprocessable();
        $this->actingAs($this->users['deputy'])->postJson("/api/v1/workflow/requests/{$workflow}/approve")->assertOk();
        $this->actingAs($this->users['matron'])->getJson("/api/v1/roster/rosters/{$id}")->assertJsonPath('data.roster.status', 'published')->assertJsonPath('data.changes_need_approval', false);

        $this->actingAs($this->users['sister'])->getJson("/api/v1/roster/rosters/{$id}")->assertJsonPath('data.changes_need_approval', true);
        $this->duties($id, [['neema', '2026-11-16', $this->morning]], $this->users['sister'], 'Asha needs a colleague')->assertOk()
            ->assertJsonPath('data.saved', 0)->assertJsonPath('data.request_id', fn ($v) => $v > 0);
        $this->assertSame('O', EmployeeTimetable::where('employee_id', $this->staff['neema']->id)->whereDate('day_date', '2026-11-16')->value('code'), 'not applied before approval');
        $this->duties($id, [['asha', '2026-11-17', $this->morning]], $this->users['sister'])->assertUnprocessable()->assertJsonValidationErrors(['duties' => 'already waiting']);
        $change = WorkflowInstance::where('process', 'duty-roster-change')->first();
        $this->actingAs($this->users['matron'])->getJson("/api/v1/workflow/requests/{$change->id}")->assertJsonPath('data.lines.2', 'Neema X, Mon 16 Nov: O → M');
        $this->postJson("/api/v1/workflow/requests/{$change->id}/approve")->assertOk();
        $this->assertSame('M', EmployeeTimetable::where('employee_id', $this->staff['neema']->id)->whereDate('day_date', '2026-11-16')->value('code'));
        $this->assertSame('applied', RosterChangeRequest::value('status'));

        $this->duties($id, [['neema', '2026-11-18', $this->morning]], $this->users['matron'])->assertOk()->assertJsonPath('data.saved', 1);
    }

    public function test_head_of_department_publishes_any_section_roster_directly_and_sees_my_team(): void
    {
        $id = $this->actingAs($this->users['matron'])->postJson('/api/v1/roster/rosters', ['department_id' => $this->ward->id, 'from_date' => '2026-11-16', 'to_date' => '2026-11-22'])->assertCreated()->json('data.id');
        $this->duties($id, [['asha', '2026-11-16', $this->morning]], $this->users['matron'])->assertOk();
        $this->postJson("/api/v1/roster/rosters/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'published');

        $this->actingAs($this->users['matron'])->getJson('/api/v1/me')->assertJsonPath('data.is_head', true);
        $this->actingAs($this->users['asha'])->getJson('/api/v1/me')->assertJsonPath('data.is_head', false);
        $team = $this->actingAs($this->users['deputy'])->getJson('/api/v1/team/summary')->assertOk()->json('data');
        $this->assertSame(['Nursing'], $team['scope']);
        $this->assertSame(5, $team['staff']);
        $this->assertSame(5, collect($team['upcoming'])->firstWhere('key', 'birthdays')['count']);
        $this->actingAs($this->users['sister'])->getJson('/api/v1/team/summary')->assertOk()->assertJsonPath('data.staff', 3);
        $this->actingAs($this->users['asha'])->getJson('/api/v1/team/summary')->assertForbidden();
    }

    public function test_heads_see_no_contract_figures_no_profiles_and_only_active_staff(): void
    {
        app(CompanyContext::class)->run($this->company, fn () => Employee::create(['employee_no' => 'LEFT', 'fname' => 'Gone', 'lname' => 'X', 'gender' => 'Female',
            'department_id' => $this->nursing->id, 'employment_status' => 'terminated']));
        $sister = $this->users['sister'];
        $this->actingAs($this->users['matron']);

        $summary = $this->getJson('/api/v1/hr/summary')->assertOk()->json('data');
        $this->assertTrue($summary['limited']);
        $this->assertNull($summary['contracts_ending']);
        $this->assertNull($summary['without_contract']);
        $this->assertNull($summary['renewals']);
        $this->assertSame([], $summary['movement']);
        $this->getJson('/api/v1/hr/stats/contracts_ending')->assertForbidden();
        $this->getJson('/api/v1/hr/stats/employees')->assertOk();

        $groups = collect($this->getJson('/api/v1/reports/hr')->assertOk()->json('data'))->pluck('group')->unique()->values()->all();
        $this->assertSame([], array_diff($groups, ['Leave', 'Duty roster', 'Overtime']));
        $this->assertContains('Duty roster', $groups);
        $this->getJson('/api/v1/reports/hr/retirement')->assertNotFound();
        $this->getJson('/api/v1/reports/hr/contracts-ending')->assertNotFound();
        $overview = $this->getJson('/api/v1/reports/overview')->assertOk()->json('data');
        $this->assertTrue($overview['limited']);
        $this->assertNull($overview['contracts']);
        $this->assertNull($overview['staff']['retiring_12_months']);

        $this->getJson("/api/v1/hr/employees/{$this->staff['asha']->id}")->assertForbidden();
        $this->getJson("/api/v1/hr/employees/{$this->staff['asha']->id}/contracts")->assertForbidden();
        $this->getJson('/api/v1/hr/employees/export')->assertForbidden();
        $names = collect($this->getJson('/api/v1/hr/employees?filter[view]=no_contract')->assertOk()->json('data'))->pluck('employee_no')->all();
        $this->assertNotContains('LEFT', $names);
        $this->assertContains('ASHA', $names);

        $team = $this->actingAs($sister)->getJson('/api/v1/team/summary')->assertOk()->json('data');
        $this->assertSame(['leave_starting', 'birthdays'], array_column($team['upcoming'], 'key'));

        $this->actingAs($this->admin)->getJson('/api/v1/hr/summary')->assertJsonPath('data.limited', false);
        $this->getJson("/api/v1/hr/employees/{$this->staff['asha']->id}")->assertOk();
    }
}
