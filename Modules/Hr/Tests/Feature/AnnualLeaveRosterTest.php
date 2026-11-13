<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\AnnualLeaveRoster;
use App\Models\Department;
use App\Models\Employee;
use App\Models\HeadOfDepartment;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Workflow\Models\WorkflowTemplate;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class AnnualLeaveRosterTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Employee> */
    private array $staff = [];

    private Department $nursing;

    private Department $lab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-12 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'leave'], ['slug' => 'self-service'], ['slug' => 'admin']]]);
        setPermissionsTeamId($this->company->id);
        $this->inCompany(function () {
            $this->nursing = Department::create(['name' => 'Nursing']);
            $ward = Department::create(['name' => 'Ward A', 'parent_id' => $this->nursing->id]);
            $this->lab = Department::create(['name' => 'Laboratory']);
            $person = function (string $key, Department $dept, ?Department $section = null, bool $annual = true, ?string $role = null) {
                $user = User::factory()->create(['company_id' => $this->company->id]);
                $user->assignRole('employee');
                if ($role) {
                    $user->assignRole($role);
                }
                $this->users[$key] = $user;

                return $this->staff[$key] = Employee::create(['employee_no' => strtoupper($key), 'fname' => ucfirst($key), 'lname' => 'X', 'gender' => 'Female', 'department_id' => $dept->id,
                    'section_id' => $section?->id, 'user_id' => $user->id, 'emp_type' => 'full_time', 'employment_status' => 'confirmed', 'has_annual_leave' => $annual, 'employment_date' => '2020-01-01']);
            };
            $person('asha', $this->nursing, $ward);
            $person('driver', $this->nursing, null, false);
            $matron = $person('matron', $this->nursing, null, true, 'hod');
            $person('labtech', $this->lab);
            HeadOfDepartment::create(['employee_id' => $matron->id, 'department_id' => $this->nursing->id, 'is_active' => true]);
            LeaveType::query()->update(['created_at' => '2026-01-01 00:00:00']);
        });
        $this->giveActiveContracts($this->company);
    }

    private function inCompany(callable $fn): mixed
    {
        return app(CompanyContext::class)->run($this->company, $fn);
    }

    private function plan(int $roster, string $who, string $start, int $days)
    {
        return $this->postJson("/api/v1/leave/annual-rosters/{$roster}/plans", ['employee_id' => $this->staff[$who]->id, 'start_date' => $start, 'days' => $days]);
    }

    public function test_plan_publish_remind_and_request_planned_leave(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/leave/annual-rosters', ['department_id' => $this->nursing->id])->assertCreated()
            ->assertJsonPath('data.title', 'Nursing · Annual leave 2026')->assertJsonPath('data.status', 'draft')->assertJsonPath('data.from_date', '2026-01-01')->json('data.id');
        $this->postJson('/api/v1/leave/annual-rosters', ['department_id' => $this->nursing->id, 'year' => 2026])->assertUnprocessable()->assertJsonValidationErrors(['year' => 'already exists']);

        $planId = $this->plan($id, 'asha', '2026-12-01', 10)->assertCreated()->assertJsonPath('data.end_date', '2026-12-10')->assertJsonPath('data.status', 'planned')->json('data.id');
        $this->plan($id, 'asha', '2026-12-05', 3)->assertUnprocessable()->assertJsonValidationErrors(['start_date' => 'already has leave planned']);
        $this->plan($id, 'asha', '2026-12-15', 20)->assertUnprocessable()->assertJsonValidationErrors(['days' => '18 days of annual leave left to plan']);
        $this->plan($id, 'driver', '2026-12-15', 5)->assertUnprocessable()->assertJsonValidationErrors(['employee_id' => 'does not give annual leave']);
        $this->plan($id, 'labtech', '2026-12-15', 5)->assertUnprocessable()->assertJsonValidationErrors(['employee_id' => 'not in this department']);
        $this->plan($id, 'asha', '2027-01-05', 5)->assertUnprocessable()->assertJsonValidationErrors(['start_date']);

        $show = $this->getJson("/api/v1/leave/annual-rosters/{$id}")->assertOk()->assertJsonCount(3, 'data.employees')->assertJsonCount(12, 'data.months');
        $asha = collect($show->json('data.employees'))->firstWhere('name', 'Asha X');
        $this->assertSame([10, 28, 1], [$asha['planned_days'], $asha['available'], count($asha['plans'])]);
        $this->assertSame(1, collect($show->json('data.months'))->firstWhere('month', '2026-12')['on_leave']);

        $this->actingAs($this->users['asha'])->getJson('/api/v1/self/leave/plans')->assertJsonCount(0, 'data');
        $this->actingAs($this->admin)->postJson("/api/v1/leave/annual-rosters/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        $this->actingAs($this->users['asha'])->getJson('/api/v1/self/leave/plans')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $planId);
        $this->getJson('/api/v1/me/notifications')->assertJsonPath('data.0.title', 'Annual leave planned');

        $this->artisan('leave:status')->expectsOutputToContain('planned leave reminders: 1')->assertSuccessful();
        $this->artisan('leave:status')->expectsOutputToContain('planned leave reminders: 0')->assertSuccessful();

        $al = $this->inCompany(fn () => LeaveType::where('code', 'AL')->value('id'));
        $ask = fn () => $this->actingAs($this->users['asha'])->post('/api/v1/self/leave/requests', ['leave_type_id' => $al, 'start_date' => '2026-12-01', 'days' => 10,
            'annual_plan_id' => $planId, 'leave_address' => 'Moshi', 'leave_phone' => '0712', 'agreed' => 'true'], ['Accept' => 'application/json']);
        $leave = $ask()->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->assertSame('requested', AnnualLeaveRoster::find($planId)->status);
        $this->actingAs($this->admin)->deleteJson("/api/v1/leave/annual-rosters/{$id}/plans/{$planId}")->assertUnprocessable();

        $this->actingAs($this->users['asha'])->postJson("/api/v1/self/leave/requests/{$leave}/withdraw")->assertOk();
        $this->assertSame(['planned', null], [AnnualLeaveRoster::find($planId)->status, AnnualLeaveRoster::find($planId)->emp_leave_id]);

        $this->inCompany(fn () => WorkflowTemplate::where('process', 'leave-request')->delete());
        $ask()->assertCreated()->assertJsonPath('data.status', 'approved');
        $this->assertSame('taken', AnnualLeaveRoster::find($planId)->status);
        $ask()->assertUnprocessable()->assertJsonValidationErrors(['annual_plan_id']);
    }

    public function test_head_of_department_plans_only_their_department(): void
    {
        $this->actingAs($this->users['matron'])->getJson('/api/v1/leave/annual-rosters')->assertOk()->assertJsonCount(2, 'meta.departments');
        $this->postJson('/api/v1/leave/annual-rosters', ['department_id' => $this->lab->id])->assertForbidden();
        $id = $this->postJson('/api/v1/leave/annual-rosters', ['department_id' => $this->nursing->id])->assertCreated()->json('data.id');
        $this->plan($id, 'asha', '2026-12-01', 5)->assertCreated();

        $lab = $this->actingAs($this->admin)->postJson('/api/v1/leave/annual-rosters', ['department_id' => $this->lab->id])->json('data.id');
        $this->actingAs($this->users['matron'])->getJson('/api/v1/leave/annual-rosters')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/leave/annual-rosters/{$lab}")->assertNotFound();
        $this->actingAs($this->users['asha'])->getJson('/api/v1/leave/annual-rosters')->assertForbidden();
    }
}
