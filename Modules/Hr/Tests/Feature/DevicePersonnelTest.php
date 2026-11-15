<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\AttendanceSetting;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobPosition;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Modules\Hr\Attendance\BiotimePersonnel;
use Modules\Hr\Attendance\PunchSource;
use Modules\Hr\Attendance\PunchSources;
use Modules\Hr\Jobs\PushStaffToDeviceJob;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class DevicePersonnelTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private Department $ward;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'attendance']]]);
        setPermissionsTeamId($this->company->id);
        config(['database.connections.biotime_fake' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $s = Schema::connection('biotime_fake');
        $s->create('personnel_department', fn (Blueprint $t) => [$t->id(), $t->string('dept_code'), $t->string('dept_name'), $t->unsignedBigInteger('parent_dept_id')->nullable()]);
        $s->create('personnel_position', fn (Blueprint $t) => [$t->id(), $t->string('position_code'), $t->string('position_name'), $t->unsignedBigInteger('parent_position_id')->nullable()]);
        $s->create('personnel_area', fn (Blueprint $t) => [$t->id(), $t->string('area_name')]);
        $s->create('personnel_employee_area', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('employee_id'), $t->unsignedBigInteger('area_id')]);
        $s->create('personnel_employee', function (Blueprint $t) {
            $t->id();
            foreach (['emp_code', 'first_name', 'last_name', 'nickname', 'hire_date', 'gender', 'birthday', 'national', 'email', 'update_time'] as $c) {
                $t->string($c)->nullable();
            }
            foreach (['department_id', 'position_id', 'verify_mode', 'dev_privilege', 'app_status', 'app_role'] as $c) {
                $t->integer($c)->nullable();
            }
        });
        DB::connection('biotime_fake')->table('personnel_area')->insert([['area_name' => 'Main gate'], ['area_name' => 'Theatre']]);
        DB::connection('biotime_fake')->table('personnel_employee')->insert(['emp_code' => '900', 'first_name' => 'Old staff']);
        $this->app->instance(PunchSources::class, new class extends PunchSources
        {
            public function forCurrentCompany(): ?PunchSource
            {
                return null;
            }

            public function personnelForCurrentCompany(): ?BiotimePersonnel
            {
                return AttendanceSetting::current()->enabled() ? new BiotimePersonnel('biotime_fake') : null;
            }
        });
        $this->ward = app(CompanyContext::class)->run($this->company, fn () => Department::create(['name' => 'Ward']));
    }

    private function hire(string $no, array $extra = []): Employee
    {
        return app(CompanyContext::class)->run($this->company, fn () => Employee::create($extra + ['employee_no' => $no, 'fname' => 'Asha', 'lname' => $no, 'gender' => 'Female',
            'department_id' => $this->ward->id, 'emp_type' => 'full_time', 'employment_status' => 'confirmed', 'has_attendance' => true]));
    }

    private function device(): \Illuminate\Support\Collection
    {
        return DB::connection('biotime_fake')->table('personnel_employee')->get()->keyBy('emp_code');
    }

    public function test_nothing_is_sent_when_the_company_does_not_use_the_devices(): void
    {
        Queue::fake();
        $this->hire('A1');
        Queue::assertNothingPushed();
        $this->actingAs($this->admin)->postJson('/api/v1/attendance/employees/push')->assertUnprocessable()->assertJsonValidationErrors(['attendance' => 'not set to use']);
    }

    public function test_new_staff_and_changes_reach_the_device_with_a_free_code(): void
    {
        app(CompanyContext::class)->run($this->company, fn () => AttendanceSetting::create(['source' => 'biotime', 'connection' => ['host' => 'x', 'database' => 'b', 'username' => 'u'], 'grace_minutes' => 0, 'sync_days' => 7]));
        $first = $this->hire('A1');
        $this->assertSame('Asha A1', $this->device()[(string) $first->id]->first_name);
        $this->assertSame((string) $first->id, $first->refresh()->attendance_code);
        $this->assertSame(2, DB::connection('biotime_fake')->table('personnel_employee_area')->where('employee_id', $this->device()[(string) $first->id]->id)->count());
        $this->assertSame('Ward', DB::connection('biotime_fake')->table('personnel_department')->value('dept_name'));

        app(CompanyContext::class)->run($this->company, fn () => Employee::whereKeyNot($first->id)->update(['attendance_code' => (string) ($first->id + 1)]));
        DB::connection('biotime_fake')->table('personnel_employee')->insert(['emp_code' => (string) ($first->id + 2), 'first_name' => 'Someone']);
        $second = $this->hire('A2', ['has_attendance' => false]);
        $this->assertArrayNotHasKey((string) $second->id, $this->device()->all());
        app(CompanyContext::class)->run($this->company, fn () => $second->update(['has_attendance' => true]));
        $code = $second->refresh()->attendance_code;
        $this->assertNotContains($code, [(string) $second->id, (string) ($first->id + 2)], 'the id is taken, so the next free number is used');
        $this->assertSame((string) (max(900, $first->id + 2) + 1), $code);

        $position = app(CompanyContext::class)->run($this->company, fn () => JobPosition::create(['title' => 'Nurse', 'department_id' => $this->ward->id, 'is_active' => true]));
        app(CompanyContext::class)->run($this->company, fn () => $first->update(['fname' => 'Amina', 'job_position_id' => $position->id]));
        $this->assertSame('Amina A1', $this->device()[(string) $first->id]->first_name);
        $this->assertSame('Nurse', DB::connection('biotime_fake')->table('personnel_position')->value('position_name'));

        $this->actingAs($this->admin)->postJson("/api/v1/attendance/employees/{$first->id}/push")->assertOk()->assertJsonPath('data.result', 'updated');
        Queue::fake();
        $this->postJson('/api/v1/attendance/employees/push')->assertOk();
        Queue::assertPushed(PushStaffToDeviceJob::class, fn ($job) => $job->employeeId === null);
        $this->artisan('attendance:push-staff')->expectsOutputToContain('Companies queued: 1')->assertSuccessful();
    }
}
