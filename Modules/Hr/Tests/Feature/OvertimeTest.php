<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeTimetable;
use App\Models\JobPosition;
use App\Models\LeaveType;
use App\Models\NatureOfContract;
use App\Models\Overtime;
use App\Models\Roster;
use App\Models\RosterCode;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Hr\Actions\ManageLeave;
use Modules\Workflow\Models\WorkflowTemplate;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class OvertimeTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private User $user;

    private User $hr;

    private Department $ward;

    private Employee $nurse;

    private Employee $intern;

    private int $position;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-12 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'roster'], ['slug' => 'leave'], ['slug' => 'overtime'], ['slug' => 'self-service']]]);
        setPermissionsTeamId($this->company->id);
        $this->inCompany(function () {
            $this->ward = Department::create(['name' => 'Ward']);
            $this->position = JobPosition::create(['title' => 'Nurse', 'department_id' => $this->ward->id, 'is_active' => true])->id;
            $stc = NatureOfContract::create(['contract_name' => 'Specific time', 'code' => 'STC', 'employment_type' => 'full_time', 'has_overtime' => true, 'has_annual_leave' => true]);
            $int = NatureOfContract::create(['contract_name' => 'Internship', 'code' => 'INT', 'employment_type' => 'internship', 'has_overtime' => false]);
            $this->user = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
            $this->hr = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('hr-officer');
            $make = function (string $no, NatureOfContract $type, ?int $userId = null) {
                $e = Employee::create(['employee_no' => $no, 'fname' => $no, 'lname' => 'X', 'gender' => 'Female', 'department_id' => $this->ward->id, 'job_position_id' => $this->position,
                    'user_id' => $userId, 'emp_type' => 'full_time', 'employment_status' => 'confirmed', 'has_annual_leave' => true, 'employment_date' => '2020-01-01']);
                EmployeeContract::create(['employee_id' => $e->id, 'nature_of_contract_id' => $type->id, 'contract_no' => $no, 'basic_salary' => 900000, 'period' => 12,
                    'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'active', 'is_active' => true]);

                return $e;
            };
            $this->nurse = $make('Nurse', $stc, $this->user->id);
            $this->intern = $make('Intern', $int);
            WorkflowTemplate::whereIn('process', ['overtime-claim', 'leave-request'])->delete();
        });
        $this->actingAs($this->admin)->postJson('/api/v1/overtime/rates', ['department_id' => $this->ward->id, 'unit' => 'hour', 'amount' => 3000])->assertCreated();
        $this->postJson('/api/v1/overtime/rates', ['department_id' => $this->ward->id, 'job_position_id' => $this->position, 'unit' => 'hour', 'amount' => 4000])->assertCreated();
        $this->postJson('/api/v1/overtime/rates', ['department_id' => $this->ward->id, 'unit' => 'shift', 'amount' => 1])->assertUnprocessable();
    }

    private function inCompany(callable $fn): mixed
    {
        return app(CompanyContext::class)->run($this->company, $fn);
    }

    private function claim(string $date, string $start, string $end)
    {
        return $this->actingAs($this->user)->postJson('/api/v1/self/overtime', ['date' => $date, 'start_time' => $start, 'end_time' => $end, 'task_title' => 'Emergency cover']);
    }

    public function test_only_time_outside_the_planned_shift_counts(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/self/overtime/preview?date=2026-11-10&start_time=15:00&end_time=19:00')->assertOk()
            ->assertJsonPath('data.total_minutes', 240)->assertJsonPath('data.planned_minutes', 120)->assertJsonPath('data.overtime_minutes', 120)
            ->assertJsonPath('data.amount', 8000)->assertJsonPath('data.shifts.0.start', '2026-11-10 08:00');
        $this->claim('2026-11-10', '15:00', '19:00')->assertCreated()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.duration', '2 h')->assertJsonPath('data.amount', 8000);
        $this->claim('2026-11-10', '09:00', '12:00')->assertUnprocessable()->assertJsonValidationErrors(['start_time' => 'inside the planned shift']);
        $this->claim('2026-11-10', '18:00', '20:00')->assertUnprocessable()->assertJsonValidationErrors(['start_time' => 'Overlaps another overtime claim']);
        $this->claim('2026-11-07', '08:00', '14:00')->assertCreated()->assertJsonPath('data.overtime_minutes', 360)->assertJsonPath('data.amount', 24000)
            ->assertJsonPath('data.planned.day_label', 'Day off');
        $this->claim('2026-11-12', '17:00', '20:00')->assertUnprocessable()->assertJsonValidationErrors(['end_time' => 'after the work is done']);

        $this->inCompany(function () {
            $n = RosterCode::create(['code' => 'N', 'duty' => 'Night', 'can_punch' => true, 'start_time' => '20:00', 'end_time' => '08:00', 'pass_day' => true, 'work_hour' => 12]);
            $o = RosterCode::create(['code' => 'O', 'duty' => 'Off', 'can_punch' => false]);
            $roster = Roster::create(['department_id' => $this->ward->id, 'type' => 'duty', 'title' => 'Ward', 'from_date' => '2026-11-03', 'to_date' => '2026-11-04', 'status' => 'published']);
            foreach ([['2026-11-03', $n], ['2026-11-04', $o]] as [$date, $code]) {
                (new EmployeeTimetable(['employee_id' => $this->nurse->id, 'day_date' => $date, 'source' => 'roster', 'roster_id' => $roster->id]))->fillFromCode($code)->save();
            }
        });
        $this->claim('2026-11-04', '06:00', '10:00')->assertCreated()->assertJsonPath('data.overtime_minutes', 120)
            ->assertJsonPath('data.planned.shifts.0.label', 'N Night')->assertJsonPath('data.planned.shifts.0.end', '2026-11-04 08:00');
        $this->claim('2026-11-05', '22:00', '02:00')->assertCreated()->assertJsonPath('data.to', '2026-11-06 02:00')->assertJsonPath('data.overtime_minutes', 240);

        $this->inCompany(fn () => app(ManageLeave::class)->request($this->nurse, ['leave_type_id' => LeaveType::where('code', 'AL')->value('id'), 'start_date' => '2026-11-09', 'days' => 1], $this->admin, 'hr'));
        $this->claim('2026-11-09', '18:00', '20:00')->assertUnprocessable()->assertJsonValidationErrors(['date' => 'is on Annual leave']);
        $this->actingAs($this->admin)->postJson("/api/v1/overtime/employees/{$this->intern->id}/claims", ['date' => '2026-11-10', 'start_time' => '17:00', 'end_time' => '19:00', 'task_title' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['employee_id' => 'does not give overtime']);

        $this->getJson('/api/v1/overtime/claims?month=2026-11')->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('totals.0.minutes', 840)->assertJsonPath('meta.total', 56000);
        $this->actingAs($this->user)->getJson('/api/v1/self/overtime?month=2026-11')->assertJsonPath('meta.minutes', 840)->assertJsonPath('meta.can_claim', true);
    }

    public function test_approver_changes_the_times_and_approves(): void
    {
        $this->inCompany(fn () => WorkflowTemplate::create(['process' => 'overtime-claim', 'is_active' => true])->steps()->create(['step_order' => 1, 'name' => 'HR', 'approver_type' => 'user', 'user_id' => $this->hr->id]));
        $id = $this->claim('2026-11-10', '17:00', '21:00')->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.waiting_for', 'HR')->json('data.id');
        $this->actingAs($this->user)->putJson("/api/v1/overtime/claims/{$id}/times", ['start_time' => '17:00', 'end_time' => '23:00'])->assertForbidden();

        $this->actingAs($this->hr)->getJson("/api/v1/overtime/claims/{$id}")->assertOk()->assertJsonPath('data.can_change', true);
        $this->putJson("/api/v1/overtime/claims/{$id}/times", ['start_time' => '17:00', 'end_time' => '20:00', 'comment' => 'Left at 8'])->assertOk()
            ->assertJsonPath('data.overtime_minutes', 180)->assertJsonPath('data.amount', 12000);
        $workflow = Overtime::find($id)->workflow;
        $this->getJson("/api/v1/workflow/requests/{$workflow->id}")->assertJsonPath('data.lines', fn ($l) => collect($l)->contains(fn ($x) => str_contains($x, 'Times changed at HR by').'' && str_contains($x, '17:00–21:00 → 17:00–20:00 (Left at 8)')));
        $this->postJson("/api/v1/workflow/requests/{$workflow->id}/approve")->assertOk();
        $this->assertSame('approved', Overtime::find($id)->status);
        $this->actingAs($this->user)->getJson('/api/v1/me/notifications')->assertJsonPath('data.0.message', 'Overtime on 10 Nov 2026 (3 h) is approved: 12,000.00.');

        $other = $this->claim('2026-11-11', '17:00', '19:00')->assertCreated()->json('data.id');
        $this->postJson("/api/v1/self/overtime/{$other}/withdraw")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->getJson('/api/v1/overtime/claims')->assertForbidden();
    }
}
