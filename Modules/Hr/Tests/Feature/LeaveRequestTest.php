<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Department;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\EmpSupervisor;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\WorkCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Hr\Actions\ManageLeave;
use Modules\Workflow\Models\WorkflowTemplate;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class LeaveRequestTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Employee> */
    private array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-12 09:00:00');
        Storage::fake('local');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'leave'], ['slug' => 'self-service'], ['slug' => 'admin']]]);
        setPermissionsTeamId($this->company->id);
        $this->inCompany(function () {
            $ward = Department::create(['name' => 'Ward']);
            $person = function (string $key, string $role, string $gender = 'Female') use ($ward) {
                $user = User::factory()->create(['company_id' => $this->company->id]);
                $user->assignRole('employee');
                if ($role !== 'employee') {
                    $user->assignRole($role);
                }
                $this->users[$key] = $user;

                return $this->staff[$key] = Employee::create(['employee_no' => strtoupper($key), 'fname' => ucfirst($key), 'lname' => 'X', 'gender' => $gender, 'department_id' => $ward->id,
                    'user_id' => $user->id, 'emp_type' => 'full_time', 'employment_status' => 'confirmed', 'has_annual_leave' => true, 'employment_date' => '2020-01-01']);
            };
            $person('asha', 'employee');
            $person('matron', 'employee');
            $person('hr', 'hr-officer');
            EmpSupervisor::create(['employee_id' => $this->staff['asha']->id, 'supervisor_id' => $this->staff['matron']->id, 'from_date' => '2026-01-01', 'status' => 'active']);
        });
    }

    private function inCompany(callable $fn): mixed
    {
        return app(CompanyContext::class)->run($this->company, $fn);
    }

    private function typeId(string $code): int
    {
        return $this->inCompany(fn () => LeaveType::where('code', $code)->value('id'));
    }

    private function ask(array $data, string $who = 'asha')
    {
        return $this->actingAs($this->users[$who])->post('/api/v1/self/leave/requests', $data + [
            'leave_address' => 'Moshi', 'leave_phone' => '0712345678', 'agreed' => 'true',
        ], ['Accept' => 'application/json']);
    }

    private function day(string $date)
    {
        return $this->inCompany(function () use ($date) {
            app(WorkCalendar::class)->forget();

            return app(WorkCalendar::class)->day($this->staff['asha']->fresh(), $date);
        });
    }

    public function test_annual_leave_goes_through_supervisor_and_hr_who_can_change_the_dates(): void
    {
        $al = $this->typeId('AL');
        $this->ask(['leave_type_id' => $al, 'start_date' => '2026-11-20', 'days' => 10])->assertUnprocessable()->assertJsonValidationErrors(['start_date' => '14 days ahead']);
        $this->ask(['leave_type_id' => $al, 'start_date' => '2026-12-01', 'days' => 40])->assertUnprocessable()->assertJsonValidationErrors(['days' => 'Only 28 days']);
        $this->ask(['leave_type_id' => $al, 'start_date' => '2026-12-01', 'days' => 10, 'agreed' => 'false'])->assertUnprocessable()->assertJsonValidationErrors(['agreed']);

        $id = $this->ask(['leave_type_id' => $al, 'start_date' => '2026-12-01', 'days' => 10, 'description' => 'Family visit'])->assertCreated()
            ->assertJsonPath('data.end_date', '2026-12-10')->assertJsonPath('data.status', 'pending')->assertJsonPath('data.waiting_for', 'Supervisor / Head of Department')
            ->assertJsonPath('data.can_withdraw', true)->json('data.id');
        $this->getJson('/api/v1/self/leave/balances')->assertJsonPath('data.0.pending', 10)->assertJsonPath('data.0.available', 18);
        $this->ask(['leave_type_id' => $al, 'start_date' => '2026-12-05', 'days' => 2])->assertUnprocessable()->assertJsonValidationErrors(['start_date' => 'overlap Annual leave']);

        $workflow = EmpLeave::find($id)->workflow->id;
        $this->actingAs($this->users['matron'])->getJson("/api/v1/leave/requests/{$id}")->assertOk()->assertJsonPath('data.can_change', true)
            ->assertJsonPath('data.balance.available', 18)->assertJsonCount(10, 'data.breakdown');
        $this->actingAs($this->users['matron'])->postJson("/api/v1/workflow/requests/{$workflow}/approve")->assertOk();
        $this->actingAs($this->users['matron'])->putJson("/api/v1/leave/requests/{$id}/dates", ['start_date' => '2026-12-02', 'days' => 8])->assertForbidden();

        $this->actingAs($this->users['hr'])->putJson("/api/v1/leave/requests/{$id}/dates", ['start_date' => '2026-12-02', 'days' => 8, 'comment' => 'Ward cover'])
            ->assertOk()->assertJsonPath('data.end_date', '2026-12-09')->assertJsonPath('data.days', 8)->assertJsonPath('data.days_requested', 10);
        $this->getJson("/api/v1/workflow/requests/{$workflow}")->assertJsonPath('data.lines', fn ($l) => collect($l)->contains(fn ($x) => str_contains($x, 'Dates changed at HR by Hr X: 2026-12-02 to 2026-12-09, 8 days (Ward cover)')));
        $this->postJson("/api/v1/workflow/requests/{$workflow}/approve")->assertOk();

        $this->assertSame('approved', EmpLeave::find($id)->status);
        $this->assertSame(['leave', 'AL'], [$this->day('2026-12-03')->kind, $this->day('2026-12-03')->code]);
        $this->assertSame('schedule', $this->day('2026-12-01')->source);
        $this->actingAs($this->users['asha'])->getJson('/api/v1/me/notifications')->assertJsonPath('data.0.title', 'Leave approved')
            ->assertJsonPath('data.0.message', 'Your Annual leave from 02 Dec 2026 to 09 Dec 2026 (8 days) is approved.');
        $this->getJson('/api/v1/self/leave/balances')->assertJsonPath('data.0.taken', 8)->assertJsonPath('data.0.available', 20);

        $this->postJson("/api/v1/self/leave/requests/{$id}/withdraw")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('schedule', $this->day('2026-12-03')->source, 'cancelled leave leaves the calendar');
    }

    public function test_documents_limits_and_compassionate_rules(): void
    {
        $this->ask(['leave_type_id' => $this->typeId('SL'), 'start_date' => '2026-11-13', 'days' => 3])->assertUnprocessable()->assertJsonValidationErrors(['files' => 'medical certificate']);
        $this->ask(['leave_type_id' => $this->typeId('SL'), 'start_date' => '2026-11-13', 'days' => 3, 'files' => [UploadedFile::fake()->create('sick.pdf', 50, 'application/pdf')]])
            ->assertCreated()->assertJsonPath('data.files.0.name', 'sick.pdf');
        $this->ask(['leave_type_id' => $this->typeId('PL'), 'start_date' => '2026-11-23', 'days' => 2])->assertUnprocessable()->assertJsonValidationErrors(['leave_type_id' => 'male']);

        $cl = $this->typeId('CL');
        $this->ask(['leave_type_id' => $cl, 'start_date' => '2026-11-23', 'days' => 5])->assertUnprocessable()->assertJsonValidationErrors(['days' => 'up to 4 days each time']);
        $this->ask(['leave_type_id' => $cl, 'start_date' => '2026-11-23', 'days' => 3])->assertUnprocessable()->assertJsonValidationErrors(['details.deceased_name', 'details.relationship', 'details.death_date']);
        $this->ask(['leave_type_id' => $cl, 'start_date' => '2026-11-23', 'days' => 3, 'details' => ['deceased_name' => 'Mzee Ali', 'relationship' => 'Father', 'death_date' => '2026-11-10']])
            ->assertCreated()->assertJsonPath('data.details.relationship', 'Father');
        $this->ask(['leave_type_id' => $cl, 'start_date' => '2026-12-14', 'days' => 2, 'details' => ['deceased_name' => 'Ali', 'relationship' => 'father', 'death_date' => '2026-11-10']])
            ->assertUnprocessable()->assertJsonValidationErrors(['details.relationship' => 'already took']);
    }

    public function test_daily_status_and_hr_records_an_early_return(): void
    {
        $this->inCompany(fn () => WorkflowTemplate::where('process', 'leave-request')->delete());
        $id = $this->actingAs($this->users['hr'])->post("/api/v1/leave/employees/{$this->staff['asha']->id}/requests", ['leave_type_id' => $this->typeId('AL'), 'start_date' => '2026-11-16', 'days' => 10], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.source', 'hr')->assertJsonPath('data.end_date', '2026-11-25')->json('data.id');

        $this->travelTo('2026-11-16 06:00:00');
        $this->artisan('leave:status')->expectsOutputToContain('Leave started: 1')->assertSuccessful();
        $this->assertSame('on_leave', EmpLeave::find($id)->status);

        $this->actingAs($this->users['hr'])->getJson("/api/v1/leave/requests/{$id}")->assertJsonPath('data.can_record_return', true);
        $this->postJson("/api/v1/leave/requests/{$id}/return", ['report_date' => '2026-11-30'])->assertUnprocessable()->assertJsonValidationErrors(['report_date']);
        $this->postJson("/api/v1/leave/requests/{$id}/return", ['report_date' => '2026-11-21', 'comment' => 'Called back'])->assertOk()
            ->assertJsonPath('data.status', 'reported')->assertJsonPath('data.days', 5)->assertJsonPath('data.end_date', '2026-11-20')->assertJsonPath('data.report_date', '2026-11-21');
        $this->assertSame('schedule', $this->day('2026-11-23')->source);
        $this->assertSame('leave', $this->day('2026-11-20')->kind);
        $this->actingAs($this->users['asha'])->getJson('/api/v1/self/leave/balances')->assertJsonPath('data.0.taken', 5);

        $other = $this->inCompany(fn () => app(ManageLeave::class)->request($this->staff['matron'], ['leave_type_id' => $this->typeId('AL'), 'start_date' => '2026-11-16', 'days' => 2], $this->users['hr'], 'hr'));
        $this->travelTo('2026-11-20 06:00:00');
        $this->artisan('leave:status')->expectsOutputToContain('reported back: 1')->assertSuccessful();
        $this->assertSame(['reported', '2026-11-18'], [EmpLeave::find($other->id)->status, EmpLeave::find($other->id)->report_date->toDateString()]);
    }

    public function test_who_can_see_leave_requests(): void
    {
        $id = $this->ask(['leave_type_id' => $this->typeId('AL'), 'start_date' => '2026-12-01', 'days' => 5])->assertCreated()->json('data.id');
        $this->actingAs($this->users['asha'])->getJson('/api/v1/self/leave/requests')->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee', null);
        $this->actingAs($this->users['asha'])->getJson("/api/v1/leave/requests/{$id}")->assertOk()->assertJsonPath('data.can_change', false);
        $this->actingAs($this->users['asha'])->getJson('/api/v1/leave/requests')->assertForbidden();
        $this->actingAs($this->users['hr'])->getJson('/api/v1/leave/requests?status=pending')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.name', 'Asha X');
        $this->actingAs($this->users['hr'])->getJson('/api/v1/leave/requests?month=2027-01')->assertJsonCount(0, 'data');
        $outsider = User::factory()->create(['company_id' => $this->company->id]);
        $this->inCompany(fn () => $outsider->assignRole('employee'));
        $this->actingAs($outsider)->getJson("/api/v1/leave/requests/{$id}")->assertNotFound();
    }
}
