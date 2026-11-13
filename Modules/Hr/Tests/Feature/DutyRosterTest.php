<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Roster;
use App\Models\RosterCode;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\PlannedDay;
use App\Services\WorkCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class DutyRosterTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private Department $nursing;

    private Department $ward;

    private Department $lab;

    /** @var array<string, Employee> */
    private array $staff = [];

    /** @var array<string, int> */
    private array $codes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-12 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'roster'], ['slug' => 'admin'], ['slug' => 'self-service']]]);
        setPermissionsTeamId($this->company->id);

        $this->inCompany(function () {
            $this->nursing = Department::create(['name' => 'Nursing', 'short_name' => 'NUR']);
            $this->ward = Department::create(['name' => 'Ward A', 'parent_id' => $this->nursing->id]);
            $this->lab = Department::create(['name' => 'Laboratory', 'short_name' => 'LAB']);
            $make = fn ($no, $dept, $section = null) => $this->staff[$no] = Employee::create(['employee_no' => $no, 'fname' => ucfirst($no), 'lname' => 'X', 'gender' => 'Female',
                'department_id' => $dept->id, 'section_id' => $section?->id, 'employment_status' => 'confirmed']);
            $make('asha', $this->nursing, $this->ward);
            $make('neema', $this->nursing, $this->ward);
            $make('juma', $this->nursing);
            $make('labtech', $this->lab);
            foreach ([['M', 'Morning', true, '07:30', '15:30', 30], ['E', 'Evening', true, '15:00', '21:00', 0], ['N', 'Night', true, '20:30', '07:30', 30], ['O', 'Off', false, null, null, 0]] as [$c, $d, $w, $s, $e, $b]) {
                $day = $w ? PlannedDay::working(now(), 'roster', null, '', $s, $e, $b) : null;
                $this->codes[$c] = RosterCode::create(['code' => $c, 'duty' => $d, 'can_punch' => $w, 'start_time' => $s, 'end_time' => $e, 'break_minutes' => $b,
                    'pass_day' => (bool) $day?->crossesMidnight, 'work_hour' => $day?->hours ?? 0, 'color' => 'blue'])->id;
            }
        });
        $this->giveActiveContracts($this->company);
    }

    private function inCompany(callable $fn): mixed
    {
        return app(CompanyContext::class)->run($this->company, $fn);
    }

    private function duties(int $roster, array $rows, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->putJson("/api/v1/roster/rosters/{$roster}/duties", ['duties' => collect($rows)->map(fn ($r) => [
            'employee_id' => $this->staff[$r[0]]->id, 'date' => $r[1], 'roster_code_id' => $r[2] ? $this->codes[$r[2]] : null, 'overtime' => $r[3] ?? false,
        ])->all()]);
    }

    private function day(string $who, string $date): PlannedDay
    {
        return $this->inCompany(function () use ($who, $date) {
            app(WorkCalendar::class)->forget();

            return app(WorkCalendar::class)->day($this->staff[$who], $date);
        });
    }

    public function test_hr_prepares_and_publishes_a_section_roster(): void
    {
        $this->postJson('/api/v1/roster/rosters', [])->assertUnauthorized();
        $this->actingAs($this->admin)->getJson('/api/v1/roster/rosters/options')->assertOk()
            ->assertJsonPath('data.codes', 4)->assertJsonFragment(['name' => 'Ward A', 'parent' => 'Nursing']);

        $id = $this->postJson('/api/v1/roster/rosters', ['department_id' => $this->ward->id, 'from_date' => '2026-12-01', 'to_date' => '2026-12-31'])
            ->assertCreated()->assertJsonPath('data.title', 'Ward A · December 2026')->assertJsonPath('data.department.name', 'Nursing')
            ->assertJsonPath('data.section.name', 'Ward A')->assertJsonPath('data.status', 'draft')->assertJsonPath('data.days', 31)->json('data.id');

        $show = $this->getJson("/api/v1/roster/rosters/{$id}")->assertOk()->assertJsonCount(31, 'data.days')->assertJsonCount(2, 'data.employees')
            ->assertJsonPath('data.can_edit', true)->assertJsonPath('data.days.8.holiday', null);
        $this->assertSame(['Asha X', 'Neema X'], collect($show->json('data.employees'))->pluck('name')->all());

        $this->duties($id, [['juma', '2026-12-01', 'M']])->assertUnprocessable()->assertJsonValidationErrors(['duties.0.employee_id' => 'not on this roster']);
        $this->duties($id, [['asha', '2027-01-01', 'M']])->assertUnprocessable()->assertJsonValidationErrors(['duties.0.date' => 'outside']);
        $this->duties($id, [['asha', '2026-12-01', 'N', true], ['asha', '2026-12-02', 'O'], ['neema', '2026-12-01', 'M'], ['neema', '2026-12-05', 'E']])
            ->assertOk()->assertJsonPath('data.saved', 4);
        $this->duties($id, [['neema', '2026-12-05', null]])->assertOk();

        $this->assertSame('schedule', $this->day('asha', '2026-12-01')->source, 'a draft does not count');

        $this->postJson("/api/v1/roster/rosters/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.employees', 2);

        $night = $this->day('asha', '2026-12-01');
        $this->assertSame(['work', 'roster', 'N', '20:30', '07:30', true, 10.5, true], [$night->kind, $night->source, $night->code, $night->start, $night->end, $night->crossesMidnight, $night->hours, $night->overtime]);
        $this->assertSame(['off', 'O'], [$this->day('asha', '2026-12-02')->kind, $this->day('asha', '2026-12-02')->code]);
        $this->assertSame('schedule', $this->day('neema', '2026-12-05')->source, 'cleared day falls back to working hours');

        $this->getJson('/api/v1/roster/rosters?month=2026-12')->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_manage', true);
        $this->getJson('/api/v1/roster/rosters?month=2027-01')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/roster/rosters', ['department_id' => $this->ward->id, 'from_date' => '2026-12-20', 'to_date' => '2027-01-10'])
            ->assertUnprocessable()->assertJsonValidationErrors(['from_date' => 'already covers']);
        $this->deleteJson("/api/v1/roster/rosters/{$id}")->assertUnprocessable();
    }

    public function test_holiday_duty_and_self_view(): void
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->inCompany(fn () => $user->assignRole('employee'));
        $this->staff['juma']->update(['user_id' => $user->id]);
        $this->actingAs($this->admin)->postJson('/api/v1/roster/holidays', ['title' => 'Independence Day', 'type' => 'yearly', 'day' => 9, 'month' => 12])->assertCreated();
        $id = $this->postJson('/api/v1/roster/rosters', ['department_id' => $this->nursing->id, 'from_date' => '2026-12-07', 'to_date' => '2026-12-13'])->json('data.id');
        $this->getJson("/api/v1/roster/rosters/{$id}")->assertJsonPath('data.days.2.holiday', 'Independence Day')->assertJsonCount(3, 'data.employees');
        $this->duties($id, [['juma', '2026-12-09', 'M'], ['juma', '2026-12-12', 'E']])->assertOk();
        $this->postJson("/api/v1/roster/rosters/{$id}/submit")->assertOk();

        $holiday = $this->day('juma', '2026-12-09');
        $this->assertSame(['work', 'Independence Day'], [$holiday->kind, $holiday->holiday]);

        $res = $this->actingAs($user)->getJson('/api/v1/self/duties?month=2026-12')->assertOk()->assertJsonCount(31, 'data');
        $this->assertSame(['M', 'Independence Day'], [$res->json('data.8.code'), $res->json('data.8.holiday')]);
        $this->assertSame(['work', 'E'], [$res->json('data.11.kind'), $res->json('data.11.code')], 'Saturday on duty');
        $this->assertSame('off', $res->json('data.12.kind'), 'Sunday off');
        $this->assertSame(24, $res->json('meta.working_days'), '23 weekdays, the holiday worked, plus the Saturday on duty');
    }

    public function test_approval_workflow_and_locked_past_days(): void
    {
        $matron = User::factory()->create(['company_id' => $this->company->id]);
        $this->inCompany(fn () => $matron->assignRole('employee'));
        $this->actingAs($this->admin)->putJson('/api/v1/admin/workflows/duty-roster', [
            'company_branch_id' => null, 'is_active' => true, 'steps' => [['name' => 'Matron', 'approver_type' => 'user', 'user_id' => $matron->id]],
        ])->assertOk();
        $id = $this->postJson('/api/v1/roster/rosters', ['department_id' => $this->ward->id, 'title' => 'Ward A late November', 'from_date' => '2026-11-10', 'to_date' => '2026-11-30'])->json('data.id');
        $this->postJson("/api/v1/roster/rosters/{$id}/submit")->assertUnprocessable()->assertJsonValidationErrors(['roster' => 'Give employees']);
        $this->duties($id, [['asha', '2026-11-11', 'M'], ['asha', '2026-11-20', 'M']])->assertOk();

        $this->postJson("/api/v1/roster/rosters/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.waiting_for', 'Matron');
        $this->duties($id, [['asha', '2026-11-21', 'M']])->assertUnprocessable()->assertJsonValidationErrors(['duties' => 'waiting for approval']);
        $this->postJson("/api/v1/roster/rosters/{$id}/withdraw")->assertOk()->assertJsonPath('data.status', 'draft');
        $this->postJson("/api/v1/roster/rosters/{$id}/submit")->assertOk();

        $workflow = Roster::find($id)->workflow;
        $this->actingAs($matron)->getJson('/api/v1/workflow/inbox')->assertJsonFragment(['title' => 'Duty roster: Ward A late November']);
        $this->postJson("/api/v1/workflow/requests/{$workflow->id}/approve")->assertOk();
        $this->assertSame('published', Roster::find($id)->status);
        $this->actingAs($this->admin);

        $this->duties($id, [['asha', '2026-11-11', 'E']])->assertUnprocessable()->assertJsonValidationErrors(['duties.0.date' => 'Past days']);
        $this->duties($id, [['asha', '2026-11-20', 'E']])->assertOk();
        $this->assertSame('E', $this->day('asha', '2026-11-20')->code);
    }

    public function test_new_roster_repeats_the_rotation_and_skips_clashes(): void
    {
        $nov = $this->actingAs($this->admin)->postJson('/api/v1/roster/rosters', ['department_id' => $this->ward->id, 'from_date' => '2026-11-01', 'to_date' => '2026-11-03'])->json('data.id');
        $this->duties($nov, [['asha', '2026-11-01', 'M'], ['asha', '2026-11-02', 'E'], ['asha', '2026-11-03', 'N'], ['neema', '2026-11-02', 'O']])->assertOk();

        $dept = $this->postJson('/api/v1/roster/rosters', ['department_id' => $this->nursing->id, 'from_date' => '2026-11-04', 'to_date' => '2026-11-08'])->json('data.id');
        $this->duties($dept, [['asha', '2026-11-05', 'M']])->assertOk();

        $next = $this->postJson('/api/v1/roster/rosters', ['department_id' => $this->ward->id, 'from_date' => '2026-11-04', 'to_date' => '2026-11-09', 'repeat_from_id' => $nov])
            ->assertCreated()->json('data.id');
        $duties = collect($this->getJson("/api/v1/roster/rosters/{$next}")->json('data.duties'));
        $asha = $duties->where('employee_id', $this->staff['asha']->id)->pluck('code', 'date')->all();
        $this->assertSame(['2026-11-04' => 'M', '2026-11-06' => 'N', '2026-11-07' => 'M', '2026-11-08' => 'E', '2026-11-09' => 'N'], $asha, '05 is on the department roster');
        $this->assertSame(['2026-11-05' => 'O', '2026-11-08' => 'O'], $duties->where('employee_id', $this->staff['neema']->id)->pluck('code', 'date')->all());

        $this->duties($next, [['asha', '2026-11-05', 'E']])->assertUnprocessable()->assertJsonValidationErrors(['duties' => 'Asha X is already on Nursing']);
    }

    public function test_head_of_department_prepares_only_their_department(): void
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->staff['juma']->update(['user_id' => $user->id]);
        $this->inCompany(function () use ($user) {
            $role = Role::findOrCreate('nursing-hod', 'web');
            $role->syncPermissions(['roster.manage', 'hr.employees.view-department']);
            $user->assignRole($role);
        });

        $this->actingAs($user)->getJson('/api/v1/roster/rosters/options')->assertOk()->assertJsonCount(2, 'data.departments');
        $this->postJson('/api/v1/roster/rosters', ['department_id' => $this->lab->id, 'from_date' => '2026-12-01', 'to_date' => '2026-12-31'])->assertForbidden();
        $mine = $this->postJson('/api/v1/roster/rosters', ['department_id' => $this->nursing->id, 'section_id' => $this->ward->id, 'from_date' => '2026-12-01', 'to_date' => '2026-12-31'])
            ->assertCreated()->json('data.id');

        $lab = $this->actingAs($this->admin)->postJson('/api/v1/roster/rosters', ['department_id' => $this->lab->id, 'from_date' => '2026-12-01', 'to_date' => '2026-12-31'])->json('data.id');
        $this->actingAs($user)->getJson('/api/v1/roster/rosters')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
        $this->getJson("/api/v1/roster/rosters/{$lab}")->assertNotFound();
        $this->duties($lab, [['labtech', '2026-12-01', 'M']], $user)->assertForbidden();
    }
}
