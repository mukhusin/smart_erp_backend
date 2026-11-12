<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\WorkCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class RosterSetupTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-12 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'roster'], ['slug' => 'admin']]]);
        setPermissionsTeamId($this->company->id);
    }

    private function inCompany(callable $fn): mixed
    {
        return app(CompanyContext::class)->run($this->company, $fn);
    }

    public function test_duty_codes_work_out_hours_and_nights(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/roster/codes', ['code' => 'n', 'duty' => 'Night', 'is_working' => true, 'start_time' => '19:30', 'end_time' => '07:30'])
            ->assertCreated()->assertJsonPath('data.code', 'N')->assertJsonPath('data.crosses_midnight', true)->assertJsonPath('data.work_hour', 12);
        $this->postJson('/api/v1/roster/codes', ['code' => 'M', 'duty' => 'Morning', 'is_working' => true, 'start_time' => '07:30', 'end_time' => '15:30', 'break_minutes' => 30])
            ->assertCreated()->assertJsonPath('data.crosses_midnight', false)->assertJsonPath('data.work_hour', 7.5);
        $off = $this->postJson('/api/v1/roster/codes', ['code' => 'O', 'duty' => 'Off duty', 'is_working' => false, 'start_time' => '08:00'])
            ->assertCreated()->assertJsonPath('data.start_time', null)->assertJsonPath('data.work_hour', 0)->json('data.id');

        $this->postJson('/api/v1/roster/codes', ['code' => 'N', 'duty' => 'Night 2', 'is_working' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['code' => 'Another duty', 'start_time', 'end_time']);
        $this->putJson("/api/v1/roster/codes/{$off}", ['code' => 'O', 'duty' => 'Off', 'is_working' => false, 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson('/api/v1/roster/codes')->assertJsonCount(3, 'data')->assertJsonPath('data.2.code', 'O');
    }

    public function test_holidays_are_yearly_or_once(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/roster/holidays', ['title' => 'Union Day', 'type' => 'yearly', 'day' => 26, 'month' => 4])
            ->assertCreated()->assertJsonPath('data.date', '2026-04-26')->assertJsonPath('data.type', 'yearly');
        $this->postJson('/api/v1/roster/holidays', ['title' => 'Eid al-Fitr', 'type' => 'once', 'date' => '2027-03-10'])->assertCreated();
        $this->postJson('/api/v1/roster/holidays', ['title' => 'Bad', 'type' => 'yearly', 'day' => 30, 'month' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors(['day' => 'does not exist']);
        $this->postJson('/api/v1/roster/holidays', ['title' => 'Again', 'type' => 'once', 'date' => '2027-04-26'])
            ->assertUnprocessable()->assertJsonValidationErrors(['date' => 'Union Day is already on this day']);

        $this->getJson('/api/v1/roster/holidays')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/roster/holidays?year=2027')->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Eid al-Fitr')->assertJsonPath('data.1.date', '2027-04-26');
    }

    public function test_working_hours_drive_the_calendar(): void
    {
        [$ward, $office, $nurse, $clerk] = $this->inCompany(function () {
            $ward = Department::create(['name' => 'Ward', 'short_name' => 'WRD']);
            $office = Department::create(['name' => 'Office', 'short_name' => 'OFF']);
            $make = fn ($no, $dept) => Employee::create(['employee_no' => $no, 'fname' => 'A', 'lname' => $no, 'gender' => 'Female', 'department_id' => $dept->id]);

            return [$ward, $office, $make('E1', $ward), $make('E2', $office)];
        });

        $this->inCompany(function () use ($clerk) {
            $day = app(WorkCalendar::class)->day($clerk, '2026-11-14');
            $this->assertSame('off', $day->kind, 'built-in: Saturday is off');
            $this->assertSame(8.0, app(WorkCalendar::class)->day($clerk, '2026-11-13')->hours);
        });

        $this->actingAs($this->admin)->getJson('/api/v1/roster/work-schedules')->assertJsonPath('data.0.built_in', true)->assertJsonPath('data.0.weekly_hours', 40)->assertJsonCount(3, 'meta.departments');

        $weekdays = collect(range(1, 5))->map(fn ($d) => ['day' => $d, 'start' => '08:00', 'end' => '16:30'])->all();
        $this->postJson('/api/v1/roster/work-schedules', ['name' => 'Office hours', 'days' => $weekdays, 'break_minutes' => 30])
            ->assertCreated()->assertJsonPath('data.is_default', true)->assertJsonPath('data.weekly_hours', 40);
        $ward6 = [...$weekdays, ['day' => 6, 'start' => '08:00', 'end' => '13:00']];
        $id = $this->postJson('/api/v1/roster/work-schedules', ['name' => 'Ward hours', 'days' => $ward6, 'department_ids' => [$ward->id]])
            ->assertCreated()->assertJsonPath('data.is_default', false)->assertJsonPath('data.departments.0.name', 'Ward')->json('data.id');
        $this->postJson('/api/v1/roster/work-schedules', ['name' => 'Bad', 'days' => [['day' => 1, 'start' => '08:00', 'end' => '17:00'], ['day' => 1, 'start' => '09:00', 'end' => '17:00']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['days.1.day']);

        $this->postJson('/api/v1/roster/holidays', ['title' => 'Independence Day', 'type' => 'yearly', 'day' => 9, 'month' => 12])->assertCreated();

        $this->inCompany(function () use ($nurse, $clerk) {
            $calendar = app(WorkCalendar::class);
            $calendar->forget();
            $saturday = $calendar->day($nurse, '2026-11-14');
            $this->assertTrue($saturday->isWorking());
            $this->assertSame(['08:00', '13:00', 5.0], [$saturday->start, $saturday->end, $saturday->hours]);
            $this->assertSame('off', $calendar->day($clerk, '2026-11-14')->kind);
            $holiday = $calendar->day($clerk, '2026-12-09');
            $this->assertSame(['holiday', 'Independence Day'], [$holiday->kind, $holiday->label]);
            $this->assertSame(4, $calendar->workingDays($clerk, '2026-12-07', '2026-12-13'), 'Mon to Sun with a holiday on Wednesday');
            $this->assertSame(5, $calendar->workingDays($nurse, '2026-12-07', '2026-12-13'));
        });

        $this->deleteJson('/api/v1/roster/work-schedules/'.$this->getJson('/api/v1/roster/work-schedules')->json('data.0.id'))
            ->assertUnprocessable();
        $this->putJson("/api/v1/roster/work-schedules/{$id}", ['name' => 'Ward hours', 'days' => $ward6, 'is_default' => true, 'department_ids' => []])
            ->assertOk()->assertJsonPath('data.is_default', true)->assertJsonPath('data.departments', []);
        $this->getJson('/api/v1/roster/work-schedules')->assertJsonPath('data.0.name', 'Ward hours')->assertJsonPath('data.1.is_default', false);
    }

    public function test_roster_setup_needs_its_permission_and_module(): void
    {
        $user = $this->inCompany(fn () => tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('employee'));
        $this->actingAs($user)->getJson('/api/v1/roster/codes')->assertForbidden();
        $this->actingAs($user)->getJson('/api/v1/roster/holidays')->assertForbidden();

        [, $other] = $this->createCompany('Beta', ['modules' => [['slug' => 'hr']]]);
        $this->actingAs($other)->getJson('/api/v1/roster/codes')->assertForbidden();
    }
}
