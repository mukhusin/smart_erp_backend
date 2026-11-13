<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Department;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\RosterCode;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class LeaveSetupTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private User $user;

    private Employee $nurse;

    private Employee $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-12 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'leave'], ['slug' => 'roster'], ['slug' => 'self-service']]]);
        setPermissionsTeamId($this->company->id);
        $this->inCompany(function () {
            $ward = Department::create(['name' => 'Ward']);
            $this->user = User::factory()->create(['company_id' => $this->company->id]);
            $this->user->assignRole('employee');
            $this->nurse = Employee::create(['employee_no' => 'N1', 'fname' => 'Asha', 'lname' => 'Ali', 'gender' => 'Female', 'department_id' => $ward->id,
                'user_id' => $this->user->id, 'emp_type' => 'full_time', 'employment_status' => 'confirmed', 'has_annual_leave' => true, 'employment_date' => '2020-01-01']);
            $this->driver = Employee::create(['employee_no' => 'D1', 'fname' => 'Juma', 'lname' => 'Musa', 'gender' => 'Male', 'department_id' => $ward->id,
                'emp_type' => 'part_time', 'employment_status' => 'confirmed', 'has_annual_leave' => false, 'employment_date' => '2020-01-01']);
        });
        $this->giveActiveContracts($this->company);
    }

    private function inCompany(callable $fn): mixed
    {
        return app(CompanyContext::class)->run($this->company, $fn);
    }

    private function type(string $code): LeaveType
    {
        return $this->inCompany(fn () => LeaveType::where('code', $code)->firstOrFail());
    }

    private function leave(Employee $e, string $code, int $days, string $status, int $year = 2026): void
    {
        $this->inCompany(fn () => EmpLeave::create(['employee_id' => $e->id, 'leave_type_id' => $this->type($code)->id, 'start_date' => "$year-03-02", 'end_date' => "$year-03-20",
            'requested_start_date' => "$year-03-02", 'requested_end_date' => "$year-03-20", 'days' => $days, 'days_requested' => $days, 'leave_year' => (string) $year, 'status' => $status]));
    }

    public function test_a_new_company_gets_the_tanzanian_leave_types_and_hr_can_change_them(): void
    {
        $list = $this->actingAs($this->admin)->getJson('/api/v1/leave/types')->assertOk()->assertJsonCount(6, 'data');
        $this->assertSame(['AL', 28, 'calendar', 28, 14], [$list->json('data.0.code'), $list->json('data.0.max_days'), $list->json('data.0.day_count'), $list->json('data.0.carry_over_days'), $list->json('data.0.notice_days')]);
        $this->assertSame('female', collect($list->json('data'))->firstWhere('code', 'ML')['gender']);

        $id = $this->postJson('/api/v1/leave/types', ['name' => 'Study leave', 'code' => 'stl', 'kind' => 'unpaid', 'gender' => 'all', 'with_pay' => true, 'max_days' => 60,
            'day_count' => 'working', 'limit_per' => 'request', 'carry_over_days' => 10, 'employee_types' => ['full_time']])
            ->assertCreated()->assertJsonPath('data.code', 'STL')->assertJsonPath('data.with_pay', false)->assertJsonPath('data.carry_over_days', null)
            ->assertJsonPath('data.employee_types', ['full_time'])->json('data.id');
        $this->postJson('/api/v1/leave/types', ['name' => 'Annual leave', 'code' => 'AL', 'kind' => 'annual', 'gender' => 'all', 'with_pay' => true, 'max_days' => 28, 'day_count' => 'bad', 'limit_per' => 'year'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'code', 'day_count']);

        $this->leave($this->nurse, 'AL', 5, 'approved');
        $this->deleteJson('/api/v1/leave/types/'.$this->type('AL')->id)->assertUnprocessable();
        $this->deleteJson("/api/v1/leave/types/{$id}")->assertNoContent();
        $this->actingAs($this->user)->getJson('/api/v1/leave/types')->assertForbidden();
    }

    public function test_leave_days_follow_the_counting_rule_and_the_working_calendar(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/roster/holidays', ['title' => 'Independence Day', 'type' => 'yearly', 'day' => 9, 'month' => 12])->assertCreated();
        $count = fn (string $code, array $q) => $this->actingAs($this->user)->getJson('/api/v1/self/leave/days?'.http_build_query(['leave_type_id' => $this->type($code)->id] + $q))->assertOk();

        $count('PL', ['start_date' => '2026-12-07', 'end_date' => '2026-12-13'])->assertJsonPath('data.days', 4)
            ->assertJsonPath('data.breakdown.2.counted', false)->assertJsonPath('data.breakdown.2.label', 'Independence Day');
        $count('AL', ['start_date' => '2026-12-07', 'end_date' => '2026-12-13'])->assertJsonPath('data.days', 7);
        $this->inCompany(fn () => LeaveType::where('code', 'SL')->update(['day_count' => 'calendar_no_holidays']));
        $count('SL', ['start_date' => '2026-12-07', 'end_date' => '2026-12-13'])->assertJsonPath('data.days', 6);
        $count('PL', ['start_date' => '2026-12-11', 'days' => 3])->assertJsonPath('data.end_date', '2026-12-15')->assertJsonPath('data.days', 3);
        $count('AL', ['start_date' => '2026-12-01', 'days' => 28])->assertJsonPath('data.end_date', '2026-12-28');
        $count('SL', ['start_date' => '2026-12-01', 'days' => 28])->assertJsonPath('data.end_date', '2026-12-29');

        $roster = $this->actingAs($this->admin)->postJson('/api/v1/roster/rosters', ['department_id' => $this->nurse->department_id, 'from_date' => '2026-12-07', 'to_date' => '2026-12-13'])->json('data.id');
        $this->inCompany(function () {
            RosterCode::create(['code' => 'N', 'duty' => 'Night', 'can_punch' => true, 'start_time' => '20:00', 'end_time' => '08:00', 'pass_day' => true, 'work_hour' => 12]);
            RosterCode::create(['code' => 'O', 'duty' => 'Off', 'can_punch' => false]);
        });
        $code = fn ($c) => $this->inCompany(fn () => RosterCode::where('code', $c)->value('id'));
        $this->putJson("/api/v1/roster/rosters/{$roster}/duties", ['duties' => [
            ['employee_id' => $this->nurse->id, 'date' => '2026-12-07', 'roster_code_id' => $code('O')],
            ['employee_id' => $this->nurse->id, 'date' => '2026-12-12', 'roster_code_id' => $code('N')],
        ]])->assertOk();
        $this->postJson("/api/v1/roster/rosters/{$roster}/submit")->assertOk();
        $count('PL', ['start_date' => '2026-12-07', 'end_date' => '2026-12-13'])->assertJsonPath('data.days', 4)
            ->assertJsonPath('data.breakdown.0.counted', false)->assertJsonPath('data.breakdown.5.label', 'N · Night');
    }

    public function test_balances_carry_over_and_respect_eligibility(): void
    {
        $this->inCompany(fn () => LeaveType::query()->update(['created_at' => '2025-01-01 00:00:00']));
        $this->leave($this->nurse, 'AL', 20, 'reported', 2025);
        $this->leave($this->nurse, 'AL', 10, 'approved');
        $this->leave($this->nurse, 'AL', 3, 'pending');
        $this->leave($this->nurse, 'AL', 4, 'rejected');

        $this->actingAs($this->admin)->postJson("/api/v1/leave/employees/{$this->nurse->id}/adjustments", ['leave_type_id' => $this->type('AL')->id, 'leave_year' => 2026, 'days' => 2.5, 'reason' => 'Opening balance from HRMS'])
            ->assertCreated()->assertJsonPath('data.days', 2.5);
        $this->postJson("/api/v1/leave/employees/{$this->nurse->id}/adjustments", ['leave_type_id' => $this->type('CL')->id, 'leave_year' => 2026, 'days' => 1, 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['leave_type_id']);

        $res = $this->getJson("/api/v1/leave/employees/{$this->nurse->id}/balances")->assertOk()->assertJsonPath('meta.can_adjust', true)->assertJsonCount(1, 'adjustments');
        $al = collect($res->json('data'))->firstWhere('leave_type.code', 'AL');
        $this->assertSame([28, 8, 2.5, 10, 3, 25.5], [$al['entitled'], $al['carried_over'], $al['adjustments'], $al['taken'], $al['pending'], $al['available']]);
        $this->assertFalse(collect($res->json('data'))->firstWhere('leave_type.code', 'PL')['eligible'], 'paternity is for men');
        $this->assertSame(['yearly' => false, 'available' => 4], collect(collect($res->json('data'))->firstWhere('leave_type.code', 'CL'))->only(['yearly', 'available'])->all());

        $driver = $this->getJson("/api/v1/leave/employees/{$this->driver->id}/balances")->json('data');
        $this->assertSame('Your contract does not give annual leave.', collect($driver)->firstWhere('leave_type.code', 'AL')['reason']);
        $this->assertSame(0, collect($driver)->firstWhere('leave_type.code', 'AL')['available']);

        $mine = $this->actingAs($this->user)->getJson('/api/v1/self/leave/balances')->assertOk()->json('data');
        $this->assertSame(['AL', 'CL', 'UL', 'ML', 'SL'], collect($mine)->pluck('leave_type.code')->all(), 'only types I can take, annual first');
        $this->getJson("/api/v1/leave/employees/{$this->driver->id}/balances")->assertNotFound();
        $this->postJson("/api/v1/leave/employees/{$this->nurse->id}/adjustments", [])->assertForbidden();
        $this->actingAs($this->admin)->getJson("/api/v1/leave/employees/{$this->nurse->id}/balances?year=2025")
            ->assertJsonPath('data.0.taken', 20)->assertJsonPath('data.0.carried_over', 0)->assertJsonPath('data.0.available', 8);
    }

    public function test_new_joiners_annual_leave_runs_from_their_joining_date_and_the_leave_year_is_configurable(): void
    {
        $this->inCompany(fn () => LeaveType::query()->update(['created_at' => '2025-01-01 00:00:00']));
        $joiner = $this->inCompany(fn () => Employee::create(['employee_no' => 'J1', 'fname' => 'Neema', 'lname' => 'Joseph', 'gender' => 'Female', 'emp_type' => 'full_time',
            'employment_status' => 'confirmed', 'has_annual_leave' => true, 'employment_date' => '2026-03-15']));
        $this->leave($joiner, 'AL', 6, 'approved');
        $al = fn (Employee $e, string $q = '') => collect($this->actingAs($this->admin)->getJson("/api/v1/leave/employees/{$e->id}/balances{$q}")->assertOk()->json('data'))->firstWhere('leave_type.code', 'AL');

        $this->actingAs($this->admin)->getJson('/api/v1/leave/settings')->assertOk()->assertJsonPath('data.leave_year_start_month', 1)
            ->assertJsonPath('data.annual_cycle_from_joining', true)->assertJsonPath('data.joiners_from', null)->assertJsonPath('data.joiners_from_default', '2025-01-01');

        $j = $al($joiner);
        $this->assertSame([2026, '2026-03-15', '2027-03-14', true, 28, 6, 22], [$j['year'], $j['period_start'], $j['period_end'], $j['from_joining'], $j['entitled'], $j['taken'], $j['available']]);
        $this->assertSame('15 Mar 2026 – 14 Mar 2027', $j['period_label']);
        $this->assertSame(0, $al($joiner, '?year=2025')['entitled'], 'no cycle before joining');
        $n = $al($this->nurse);
        $this->assertSame([2026, '2026-01-01', '2026-12-31', false, '2026'], [$n['year'], $n['period_start'], $n['period_end'], $n['from_joining'], $n['period_label']]);
        $this->assertSame(['SL', '2026-01-01'], [collect($this->getJson("/api/v1/leave/employees/{$joiner->id}/balances")->json('data'))->firstWhere('leave_type.code', 'SL')['leave_type']['code'],
            collect($this->getJson("/api/v1/leave/employees/{$joiner->id}/balances")->json('data'))->firstWhere('leave_type.code', 'SL')['period_start']], 'other types follow the company year');

        $this->putJson('/api/v1/leave/settings', ['leave_year_start_month' => 7, 'annual_cycle_from_joining' => true])->assertOk()->assertJsonPath('data.current_year', 2026);
        $n = $al($this->nurse);
        $this->assertSame(['2026-07-01', '2027-06-30', '01 Jul 2026 – 30 Jun 2027'], [$n['period_start'], $n['period_end'], $n['period_label']]);

        $this->putJson('/api/v1/leave/settings', ['leave_year_start_month' => 1, 'annual_cycle_from_joining' => true, 'joiners_from' => '2027-01-01'])->assertOk();
        $this->assertFalse($al($joiner)['from_joining'], 'joined before the new joiners date');
        $this->putJson('/api/v1/leave/settings', ['leave_year_start_month' => 1, 'annual_cycle_from_joining' => false, 'joiners_from' => null])->assertOk();
        $this->assertSame([false, '2026-01-01'], [$al($joiner)['from_joining'], $al($joiner)['period_start']]);
        $this->actingAs($this->user)->getJson('/api/v1/leave/settings')->assertForbidden();
    }
}
