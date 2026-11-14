<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\AttendanceClaim;
use App\Models\AttendancePunch;
use App\Models\AttendanceSyncDay;
use App\Models\AttendanceTransa;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeTimetable;
use App\Models\NatureOfContract;
use App\Models\PayrollDateSetting;
use App\Models\PayrollSheetDetail;
use App\Models\Roster;
use App\Models\RosterCode;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Modules\Hr\Attendance\PunchSource;
use Modules\Hr\Attendance\PunchSources;
use Modules\Workflow\Models\WorkflowTemplate;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private User $user;

    private Employee $nurse;

    private Employee $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-12 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'roster'], ['slug' => 'leave'], ['slug' => 'attendance'], ['slug' => 'payroll'], ['slug' => 'self-service'], ['slug' => 'overtime']]]);
        setPermissionsTeamId($this->company->id);
        $this->inCompany(function () {
            $ward = Department::create(['name' => 'Ward']);
            $stc = NatureOfContract::create(['contract_name' => 'Specific time', 'code' => 'STC', 'employment_type' => 'full_time', 'has_payroll' => true, 'has_annual_leave' => true, 'has_overtime' => true]);
            $this->user = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee');
            $make = function (string $no, ?int $userId = null) use ($ward, $stc) {
                $e = Employee::create(['employee_no' => $no, 'fname' => $no, 'lname' => 'X', 'gender' => 'Female', 'department_id' => $ward->id, 'user_id' => $userId,
                    'emp_type' => 'full_time', 'emp_category' => 'Primary', 'employment_status' => 'confirmed', 'has_annual_leave' => true, 'employment_date' => '2025-01-01']);
                EmployeeContract::create(['employee_id' => $e->id, 'nature_of_contract_id' => $stc->id, 'contract_no' => $no, 'basic_salary' => 930000, 'period' => 12,
                    'start_date' => '2026-01-01', 'end_date' => '2027-06-30', 'status' => 'active', 'is_active' => true]);

                return $e;
            };
            $this->nurse = $make('Nurse', $this->user->id);
            $this->clerk = $make('Clerk');
            WorkflowTemplate::where('process', 'attendance-claim')->delete();
        });
        $this->app->instance(PunchSources::class, new class extends PunchSources
        {
            public function forCurrentCompany(): ?PunchSource
            {
                return null;
            }
        });
        $this->actingAs($this->admin)->putJson('/api/v1/attendance/settings', ['source' => 'biotime', 'host' => '10.0.0.5', 'database' => 'biotime', 'username' => 'reader', 'grace_minutes' => 10])
            ->assertOk()->assertJsonPath('data.grace_minutes', 10);
        $this->putJson('/api/v1/attendance/employees', ['employees' => [
            ['id' => $this->nurse->id, 'has_attendance' => true, 'attendance_code' => '1001'],
            ['id' => $this->clerk->id, 'has_attendance' => true],
        ]])->assertOk()->assertJsonPath('data.updated', 2);
    }

    private function inCompany(callable $fn): mixed
    {
        return app(CompanyContext::class)->run($this->company, $fn);
    }

    private function csv(array $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('punches.csv', "emp_code,punch_time,terminal\n".implode("\n", array_map(fn ($r) => implode(',', $r), $rows)));
    }

    private function day(string $date, ?Employee $e = null): AttendanceTransa
    {
        return $this->inCompany(fn () => AttendanceTransa::where('employee_id', ($e ?? $this->nurse)->id)->whereDate('transaction_date', $date)->firstOrFail());
    }

    public function test_attendance_is_worked_out_from_punches_and_the_plan(): void
    {
        $this->inCompany(function () {
            $n = RosterCode::create(['code' => 'N', 'duty' => 'Night', 'can_punch' => true, 'start_time' => '20:00', 'end_time' => '08:00', 'pass_day' => true, 'work_hour' => 12]);
            $o = RosterCode::create(['code' => 'O', 'duty' => 'Off', 'can_punch' => false]);
            $roster = Roster::create(['department_id' => $this->nurse->department_id, 'type' => 'duty', 'title' => 'Ward', 'from_date' => '2026-11-05', 'to_date' => '2026-11-06', 'status' => 'published']);
            foreach ([['2026-11-05', $n], ['2026-11-06', $o]] as [$date, $code]) {
                (new EmployeeTimetable(['employee_id' => $this->nurse->id, 'day_date' => $date, 'source' => 'roster', 'roster_id' => $roster->id]))->fillFromCode($code)->save();
            }
        });
        $this->postJson('/api/v1/attendance/punches/import', ['file' => $this->csv([
            ['1001', '2026-11-05 19:50', 'Gate'], ['1001', '2026-11-06 08:05', 'Gate'],
            ['1001', '2026-11-08 09:00', ''], ['1001', '2026-11-08 12:00', ''],
            ['1001', '2026-11-09 08:25', ''], ['1001', '2026-11-09 17:05', ''],
            ['1001', '2026-11-10 08:00', ''],
            ['1001', '2026-11-12 07:55', ''], ['1001', '2026-11-12 07:55', ''],
            ['9999', '2026-11-12 08:00', ''],
        ])])->assertCreated()->assertJsonPath('data.added', 9)->assertJsonPath('data.skipped', 1)->assertJsonPath('data.unknown', ['9999']);
        $this->postJson('/api/v1/attendance/compile', ['from' => '2026-11-05', 'to' => '2026-11-12'])->assertOk()->assertJsonPath('data.rows', 16);

        $night = $this->day('2026-11-05');
        $this->assertSame(['Present', '2026-11-05 19:50:00', '2026-11-06 08:05:00', 5, 0.0], [$night->status, $night->punch_in_time, $night->punch_out_time, (int) $night->overtime_minutes, (float) $night->lost_hour]);
        $this->assertSame([null, 'O · Off'], [$this->day('2026-11-06')->punch_in_time, $this->day('2026-11-06')->status], 'the morning punch belongs to the night shift');
        $this->assertSame(['Day off', 180], [$this->day('2026-11-08')->status, (int) $this->day('2026-11-08')->overtime_minutes]);

        $this->postJson('/api/v1/overtime/rates', ['department_id' => $this->nurse->department_id, 'unit' => 'hour', 'amount' => 3000])->assertCreated();
        $suggested = $this->getJson('/api/v1/overtime/suggestions?month=2026-11')->assertOk()->assertJsonPath('meta.attendance', true)->json('data');
        $this->assertSame([['2026-11-08', '2026-11-08 09:00', '2026-11-08 12:00', 180, true]],
            array_map(fn ($r) => [$r['date'], $r['start'], $r['end'], $r['overtime_minutes'], $r['day_off']], $suggested), 'the 5 minutes after the night shift are below the minimum');
        $this->postJson('/api/v1/overtime/suggestions', ['items' => [['employee_id' => $this->nurse->id, 'date' => '2026-11-08'], ['employee_id' => $this->nurse->id, 'date' => '2026-11-09']]])
            ->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.failed.0.message', 'No unclaimed overtime on this day.');
        $claim = $this->inCompany(fn () => \App\Models\Overtime::where('employee_id', $this->nurse->id)->sole());
        $this->assertSame(['attendance', 180, 9000.0], [$claim->source, (int) $claim->overtime_minutes, (float) $claim->amount]);
        $this->getJson('/api/v1/overtime/suggestions?month=2026-11')->assertJsonCount(0, 'data');
        $late = $this->day('2026-11-09');
        $this->assertSame(['Late', 15, 0.25, 937.5], [$late->status, (int) $late->punch_in_late_minutes, (float) $late->lost_hour, (float) $late->lost_amount], '25 min late less 10 grace; 30,000 a day / 8 h');
        $this->assertSame(['Not checked out', 8.0, 30000.0], [$this->day('2026-11-10')->status, (float) $this->day('2026-11-10')->lost_hour, (float) $this->day('2026-11-10')->lost_amount]);
        $this->assertSame(['Absent', 30000.0], [$this->day('2026-11-11')->status, (float) $this->day('2026-11-11')->lost_amount]);
        $this->assertSame('Present', $this->day('2026-11-12')->status, 'still at work');
        $this->assertSame('Waiting', $this->day('2026-11-12', $this->clerk)->status);

        $this->getJson('/api/v1/attendance/days?date=2026-11-11')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.counts.Absent', 2)->assertJsonPath('meta.lost_amount', 60000);
        $this->getJson("/api/v1/attendance/employees/{$this->nurse->id}/month?month=2026-11")->assertOk()->assertJsonPath('meta.totals.days_present', 4)
            ->assertJsonPath('meta.totals.days_absent', 2)->assertJsonCount(8, 'punches');

        $this->putJson('/api/v1/attendance/employees', ['employees' => [['id' => $this->clerk->id, 'has_attendance' => true, 'attendance_code' => '9999']]])->assertJsonPath('data.relinked', 1);
        $this->postJson('/api/v1/attendance/punches', ['employee_id' => $this->clerk->id, 'punch_time' => '2026-11-11 16:00'])->assertUnprocessable()->assertJsonValidationErrors(['note']);
        $this->postJson('/api/v1/attendance/punches', ['employee_id' => $this->clerk->id, 'punch_time' => '2026-11-11 08:00', 'note' => 'Device down'])->assertCreated();
        $this->postJson('/api/v1/attendance/punches', ['employee_id' => $this->clerk->id, 'punch_time' => '2026-11-11 17:00', 'note' => 'Device down'])->assertCreated();
        $this->assertSame('Present', $this->day('2026-11-11', $this->clerk)->status);

        $this->postJson('/api/v1/attendance/absences', ['kind' => 'excuse', 'employee_id' => $this->nurse->id, 'start_date' => '2026-11-10', 'end_date' => '2026-11-11', 'hospital_name' => 'Kairuki', 'doctor_name' => 'Dr A'])
            ->assertCreated()->assertJsonPath('data.number_of_days', 2);
        $this->assertSame(['Excuse of duty', 0.0], [$this->day('2026-11-11')->status, (float) $this->day('2026-11-11')->lost_hour]);
        $id = $this->getJson('/api/v1/attendance/absences?month=2026-11')->assertJsonCount(1, 'data')->json('data.0.id');
        $this->deleteJson("/api/v1/attendance/absences/excuse/{$id}")->assertNoContent();
        $this->assertSame('Absent', $this->day('2026-11-11')->status);
    }

    public function test_claims_forgive_lost_time_and_payroll_deducts_the_rest(): void
    {
        $hr = $this->inCompany(fn () => tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('hr-officer'));
        $this->inCompany(fn () => WorkflowTemplate::create(['process' => 'attendance-claim', 'is_active' => true])->steps()->create(['step_order' => 1, 'name' => 'HR', 'approver_type' => 'user', 'user_id' => $hr->id]));
        $this->postJson('/api/v1/attendance/punches/import', ['file' => $this->csv([['1001', '2026-11-09 08:00', ''], ['1001', '2026-11-09 17:00', ''], ['1001', '2026-11-10 08:00', '']])])->assertCreated();
        $this->postJson('/api/v1/attendance/compile', ['from' => '2026-11-09', 'to' => '2026-11-11', 'employee_id' => $this->nurse->id])->assertOk();

        $me = $this->actingAs($this->user)->getJson('/api/v1/self/attendance?month=2026-11')->assertOk()->assertJsonPath('tracked', true)->assertJsonPath('meta.totals.lost_amount', 60000);
        $days = collect($me->json('data'))->keyBy('date');
        $this->postJson("/api/v1/self/attendance/{$days['2026-11-09']['id']}/claims", ['description' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['day']);
        $this->postJson("/api/v1/self/attendance/{$days['2026-11-10']['id']}/claims", ['description' => 'Forgot to punch out, left at 17:00'])->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.waiting_for', 'HR')->assertJsonPath('data.claimed_hours', 8);
        $this->postJson("/api/v1/self/attendance/{$days['2026-11-10']['id']}/claims", ['description' => 'again'])->assertUnprocessable();

        $workflow = $this->inCompany(fn () => AttendanceClaim::first()->workflow);
        $this->actingAs($hr)->postJson("/api/v1/workflow/requests/{$workflow->id}/approve")->assertOk();
        $this->assertSame([0.0, 8.0], [(float) $this->day('2026-11-10')->lost_hour, (float) $this->day('2026-11-10')->accepted_hour]);
        $this->actingAs($this->user)->getJson('/api/v1/me/notifications')->assertJsonPath('data.0.title', 'Attendance claim approved');
        $this->getJson('/api/v1/attendance/days')->assertForbidden();

        $this->inCompany(fn () => tap(PayrollDateSetting::current(), fn ($s) => $s->fill(['use_attendance' => true])->save()));
        $id = $this->actingAs($this->admin)->postJson('/api/v1/payroll/sheets', ['year' => 2026, 'month' => 11])->assertCreated()->json('data.id');
        $deductions = $this->inCompany(fn () => PayrollSheetDetail::where('payroll_sheet_id', $id)->get()->mapWithKeys(fn ($d) => [$d->employee_id => (float) $d->attendance_deduction]));
        $this->assertSame(30000.0, $deductions[$this->nurse->id], 'only the absent day is deducted');
        $this->assertSame(60000.0, $deductions[$this->clerk->id], 'no punches on 9 and 10 Nov');
    }

    public function test_a_company_without_attendance_does_not_use_it_anywhere(): void
    {
        $this->postJson('/api/v1/attendance/punches/import', ['file' => $this->csv([['1001', '2026-11-10 08:00', '']])])->assertCreated();
        $this->assertSame('Not checked out', $this->day('2026-11-10')->status);
        $this->inCompany(fn () => tap(PayrollDateSetting::current(), fn ($s) => $s->fill(['use_attendance' => true])->save()));

        $this->putJson('/api/v1/attendance/settings', ['source' => 'none'])->assertOk()->assertJsonPath('data.source', 'none');
        $this->postJson('/api/v1/attendance/compile', ['from' => '2026-11-09', 'to' => '2026-11-11'])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'not used'));
        $this->postJson('/api/v1/attendance/punches', ['employee_id' => $this->nurse->id, 'punch_time' => '2026-11-11 08:00', 'note' => 'x'])->assertStatus(422);
        $this->artisan('attendance:sync')->expectsOutputToContain('queued: 0')->assertSuccessful();
        $this->actingAs($this->user)->getJson('/api/v1/self/attendance')->assertOk()->assertJsonPath('tracked', false);

        $id = $this->actingAs($this->admin)->postJson('/api/v1/payroll/sheets', ['year' => 2026, 'month' => 11])->assertCreated()->json('data.id');
        $this->assertSame(0.0, $this->inCompany(fn () => (float) PayrollSheetDetail::where('payroll_sheet_id', $id)->sum('attendance_deduction')));
    }

    public function test_device_sync_waits_for_an_unreachable_server(): void
    {
        $fake = new class implements PunchSource
        {
            public bool $up = false;

            public function reachable(): bool
            {
                return $this->up;
            }

            public function punches(Carbon $date): iterable
            {
                return $date->isSameDay('2026-11-11') ? [['emp_code' => '1001', 'punch_time' => '2026-11-11 07:58', 'area_alias' => 'Main'], ['emp_code' => '1001', 'punch_time' => '2026-11-11 17:02']] : [];
            }
        };
        $this->app->instance(PunchSources::class, new class($fake) extends PunchSources
        {
            public function __construct(private PunchSource $fake) {}

            public function forCurrentCompany(): ?PunchSource
            {
                return $this->fake;
            }
        });
        $this->putJson('/api/v1/attendance/settings', ['source' => 'biotime', 'host' => '10.0.0.5', 'database' => 'biotime', 'username' => 'reader', 'password' => 'secret', 'sync_days' => 3])
            ->assertOk()->assertJsonPath('data.has_password', true)->assertJsonMissingPath('data.password');
        $this->putJson('/api/v1/attendance/settings', ['source' => 'biotime', 'host' => '10.0.0.5', 'database' => 'biotime', 'username' => 'reader'])->assertJsonPath('data.has_password', true);

        $this->artisan('attendance:sync')->assertSuccessful();
        $this->assertSame(['queued'], $this->inCompany(fn () => AttendanceSyncDay::pluck('status')->unique()->values()->all()));
        $this->assertSame(0, $this->inCompany(fn () => AttendancePunch::count()));

        $fake->up = true;
        $this->travel(3)->hours();
        $this->artisan('attendance:sync')->assertSuccessful();
        $this->assertSame(['processed'], $this->inCompany(fn () => AttendanceSyncDay::pluck('status')->unique()->values()->all()));
        $this->assertSame(['Present', 'Main'], [$this->day('2026-11-11')->status, $this->day('2026-11-11')->in_area_alias]);
        $this->getJson('/api/v1/attendance/settings')->assertJsonCount(4, 'data.days')->assertJsonPath('data.last_error', null);
    }

    public function test_official_absence_is_excused_in_attendance_and_goes_through_approval(): void
    {
        $leaveType = $this->inCompany(fn () => \App\Models\LeaveType::value('id'));
        $this->inCompany(fn () => \App\Models\EmpLeave::create(['employee_id' => $this->nurse->id, 'leave_type_id' => $leaveType, 'start_date' => '2026-11-20', 'end_date' => '2026-11-22',
            'requested_start_date' => '2026-11-20', 'requested_end_date' => '2026-11-22', 'days' => 3, 'days_requested' => 3, 'leave_year' => 2026, 'status' => 'approved']));
        $record = fn (array $d) => $this->actingAs($this->admin)->postJson('/api/v1/leave/absences', $d + ['address' => 'Dodoma', 'description' => 'Workshop', 'has_per_diem' => true]);

        $rows = $record(['employee_ids' => [$this->nurse->id, $this->clerk->id], 'from_date' => '2026-11-09', 'to_date' => '2026-11-10'])
            ->assertCreated()->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'ended')->assertJsonPath('data.0.number_of_days', 2)->json('data');
        $this->assertSame('Excused absence', $this->day('2026-11-10', $this->clerk)->status);

        $record(['employee_ids' => [$this->clerk->id], 'from_date' => '2026-11-10', 'to_date' => '2026-11-11'])->assertUnprocessable()->assertJsonValidationErrors(['employee_ids' => 'already has an official absence']);
        $record(['employee_ids' => [$this->nurse->id], 'from_date' => '2026-11-21', 'to_date' => '2026-11-23'])->assertUnprocessable()->assertJsonValidationErrors(['employee_ids' => 'on leave']);
        $record(['employee_ids' => [$this->nurse->id], 'from_date' => '2026-11-12', 'to_date' => '2026-11-11'])->assertUnprocessable()->assertJsonValidationErrors(['to_date']);

        $this->inCompany(fn () => WorkflowTemplate::create(['process' => 'absence-request', 'is_active' => true])->steps()->create(['step_order' => 1, 'name' => 'HR Manager', 'approver_type' => 'user', 'user_id' => $this->admin->id]));
        $pending = $record(['employee_ids' => [$this->clerk->id], 'from_date' => '2026-11-12', 'to_date' => '2026-11-12', 'from_time' => '08:00', 'to_time' => '17:00'])
            ->assertCreated()->assertJsonPath('data.0.status', 'pending')->assertJsonPath('data.0.waiting_for', 'HR Manager')->assertJsonPath('data.0.can_cancel', true)->json('data.0.id');
        $this->postJson("/api/v1/leave/absences/{$pending}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/leave/absences/{$rows[0]['id']}/cancel")->assertUnprocessable();

        $this->getJson('/api/v1/leave/absences?month=2026-11')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/leave/absences?month=2026-11&status=ended')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/leave/absences?month=2026-10')->assertJsonCount(0, 'data');
        $this->actingAs($this->user)->postJson('/api/v1/leave/absences', ['employee_ids' => [$this->clerk->id], 'from_date' => '2026-11-13', 'to_date' => '2026-11-13', 'address' => 'x', 'description' => 'y'])->assertForbidden();
    }
}
