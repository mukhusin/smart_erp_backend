<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\AttendanceClaim;
use App\Models\AttendanceSetting;
use App\Models\AttendanceTransa;
use App\Models\Department;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\LeaveType;
use App\Models\NatureOfContract;
use App\Models\Overtime;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-12 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'reports'], ['slug' => 'leave'], ['slug' => 'overtime'], ['slug' => 'attendance']]]);
        setPermissionsTeamId($this->company->id);

        app(CompanyContext::class)->run($this->company, function () {
            Employee::query()->update(['employment_date' => '2020-01-01']);
            $dept = Department::value('id');
            $this->other = Department::create(['name' => 'Pharmacy', 'is_active' => true])->id;
            $type = NatureOfContract::create(['contract_name' => 'Specific time', 'code' => 'STC', 'employment_type' => 'full_time'])->id;
            $make = fn (array $a) => Employee::create($a + ['lname' => 'X', 'gender' => 'Female', 'emp_type' => 'full_time', 'employment_status' => 'confirmed', 'department_id' => $dept, 'employment_date' => '2020-01-01']);
            $contract = fn (Employee $e, array $a) => EmployeeContract::create($a + ['employee_id' => $e->id, 'nature_of_contract_id' => $type, 'contract_no' => $e->employee_no,
                'basic_salary' => 1000000, 'period' => 12, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'active', 'is_active' => true]);

            $asha = $make(['employee_no' => 'A1', 'fname' => 'Asha', 'date_of_birth' => '1966-12-03']);
            $contract($asha, []);
            $juma = $make(['employee_no' => 'A2', 'fname' => 'Juma', 'gender' => 'Male', 'employment_date' => '2026-11-05', 'department_id' => $this->other]);
            $contract($juma, ['end_date' => '2026-12-01', 'basic_salary' => 500000]);
            $neema = $make(['employee_no' => 'A3', 'fname' => 'Neema', 'employment_status' => 'terminated']);
            $contract($neema, ['status' => 'resigned_30_days_notes', 'is_active' => false, 'date_of_change' => '2026-11-03']);

            $leave = LeaveType::where('kind', 'annual')->first();
            Overtime::create(['employee_id' => $juma->id, 'department_id' => $this->other, 'hours' => 5, 'minutes' => 0, 'overtime_minutes' => 300, 'amount' => 20000, 'task_title' => 'Cover',
                'date_of_task_done' => '2026-11-10', 'from_datetime' => '2026-11-10 17:00', 'to_datetime' => '2026-11-10 22:00', 'status' => 'approved', 'type' => 'claim']);
            AttendanceSetting::create(['source' => 'biotime', 'grace_minutes' => 0, 'sync_days' => 60]);
            $juma->update(['has_attendance' => true]);
            $day = AttendanceTransa::create(['employee_id' => $juma->id, 'transaction_date' => '2026-11-11', 'status' => 'Late', 'punch_in_time' => '2026-11-11 08:30:00',
                'punch_in_late_minutes' => 30, 'lost_hour' => 0.5, 'lost_amount' => 2000]);
            AttendanceTransa::create(['employee_id' => $juma->id, 'transaction_date' => '2026-11-10', 'status' => 'Absent', 'lost_hour' => 8, 'lost_amount' => 30000]);
            AttendanceClaim::create(['attendance_transa_id' => $day->id, 'employee_id' => $juma->id, 'claim_date' => '2026-11-11', 'lost_hour' => 0.5, 'hr_accepted' => 0.5,
                'lost_amount' => 2000, 'description' => 'Traffic', 'claim_status' => 'pending']);
            EmpLeave::create(['employee_id' => $asha->id, 'leave_type_id' => $leave->id, 'start_date' => '2026-11-10', 'end_date' => '2026-11-20', 'requested_start_date' => '2026-11-10', 'requested_end_date' => '2026-11-20', 'days' => 9, 'days_requested' => 9, 'leave_year' => 2026, 'status' => 'on_leave']);
        });
        $this->giveActiveContracts($this->company);
    }

    public function test_lists_reports_and_runs_them(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/reports/hr')->assertOk()->assertJsonPath('data.0.key', 'headcount');

        $headcount = $this->actingAs($this->admin)->getJson('/api/v1/reports/hr/headcount')->assertOk()->json('data');
        $this->assertSame('Total', end($headcount['rows'])['department']);
        $this->assertSame(3, end($headcount['rows'])['total']);

        $this->actingAs($this->admin)->getJson('/api/v1/reports/hr/retirement?age=60&months=1')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.rows.0.retires_on', '2026-12-03');
        $this->actingAs($this->admin)->getJson('/api/v1/reports/hr/birthdays?month=12')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.rows.0.age', 60);
        $this->actingAs($this->admin)->getJson('/api/v1/reports/hr/joiners')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.rows.0.employee_no', 'A2');
        $this->actingAs($this->admin)->getJson('/api/v1/reports/hr/leavers?from=2026-11-01&to=2026-11-30')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.rows.0.reason', 'Resignation, 30 days notice');
        $this->actingAs($this->admin)->getJson('/api/v1/reports/hr/contracts-ending?days=30')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.rows.0.days_left', 19)->assertJsonPath('data.rows.0.renewal', 'Not renewed yet');
        $this->actingAs($this->admin)->getJson('/api/v1/reports/hr/on-leave')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.rows.0.leave_type', 'Annual leave');
        $this->actingAs($this->admin)->getJson("/api/v1/reports/hr/contacts?department_id={$this->other}")->assertOk()->assertJsonPath('data.total', 1);

        $this->actingAs($this->admin)->getJson('/api/v1/reports/hr/nope')->assertNotFound();
        $this->actingAs($this->admin)->getJson('/api/v1/reports/hr/joiners?from=2026-11-10&to=2026-11-01')->assertUnprocessable();

        $csv = $this->actingAs($this->admin)->get('/api/v1/reports/hr/joiners/csv')->assertOk()->getContent();
        $this->actingAs($this->admin)->get('/api/v1/reports/hr/joiners/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('New employees', $csv);
        $this->assertStringContainsString('A2', $csv);
    }

    public function test_leave_overtime_and_attendance_reports(): void
    {
        $keys = array_column($this->actingAs($this->admin)->getJson('/api/v1/reports/hr')->json('data'), 'key');
        $this->assertContains('attendance-summary', $keys);

        $this->actingAs($this->admin)->getJson('/api/v1/reports/hr/leave-requests')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.rows.0.status', 'On leave');
        $balances = $this->actingAs($this->admin)->getJson('/api/v1/reports/hr/leave-balances')->assertOk()->json('data');
        $asha = collect($balances['rows'])->firstWhere('employee_no', 'A1');
        $this->assertEquals(9, $asha['taken']);

        $this->actingAs($this->admin)->getJson('/api/v1/reports/hr/overtime')->assertOk()
            ->assertJsonPath('data.rows.0.hours', 5)->assertJsonPath('data.rows.0.amount', 20000);
        $this->actingAs($this->admin)->getJson('/api/v1/reports/hr/overtime-summary')->assertOk()
            ->assertJsonPath('data.rows.1.name', 'Total')->assertJsonPath('data.rows.1.hours', 5);

        $this->actingAs($this->admin)->getJson('/api/v1/reports/hr/attendance-daily?from=2026-11-10&to=2026-11-11&status=Late')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.rows.0.check_in', '08:30')->assertJsonPath('data.rows.0.late_minutes', 30);
        $summary = $this->actingAs($this->admin)->getJson('/api/v1/reports/hr/attendance-summary')->assertOk()->json('data.rows.0');
        $this->assertSame(['recorded' => 2, 'late' => 1, 'absent' => 1], array_intersect_key($summary, array_flip(['recorded', 'late', 'absent'])));
        $this->assertEquals(8.5, $summary['lost_hours']);
        $this->actingAs($this->admin)->getJson('/api/v1/reports/hr/attendance-claims')->assertOk()
            ->assertJsonPath('data.rows.0.reason', 'Traffic')->assertJsonPath('data.rows.0.status', 'Waiting for approval');

        app(CompanyContext::class)->run($this->company, fn () => AttendanceSetting::query()->update(['source' => 'none']));
        $this->actingAs($this->admin)->getJson('/api/v1/reports/hr/attendance-summary')->assertNotFound();
        $keys = array_column($this->actingAs($this->admin)->getJson('/api/v1/reports/hr')->json('data'), 'key');
        $this->assertNotContains('attendance-summary', $keys);
    }

    public function test_hod_sees_own_department_without_money(): void
    {
        $hod = User::factory()->create(['company_id' => $this->company->id]);
        $hod->assignRole('hod');
        app(CompanyContext::class)->run($this->company, fn () => Employee::create(['employee_no' => 'H1', 'fname' => 'Hamisi', 'lname' => 'Y', 'gender' => 'Male',
            'emp_type' => 'full_time', 'employment_status' => 'confirmed', 'department_id' => $this->other, 'user_id' => $hod->id]));

        $this->giveActiveContracts($this->company);
        $data = $this->actingAs($hod)->getJson('/api/v1/reports/overview')->assertOk()->json('data');
        $this->assertSame(2, $data['staff']['active']);
        $this->assertNull($data['salary']);
        $this->assertSame(0, $data['my_approvals']);
        $this->actingAs($hod)->getJson('/api/v1/reports/hr/overtime')->assertOk()
            ->assertJsonPath('data.rows.0.employee_no', 'A2')->assertJsonMissingPath('data.rows.0.amount');
    }

    public function test_overview_and_access(): void
    {
        $data = $this->actingAs($this->admin)->getJson('/api/v1/reports/overview')->assertOk()->json('data');
        $this->assertSame(3, $data['staff']['active']);
        $this->assertSame(1, $data['staff']['joined_this_month']);
        $this->assertSame(1, $data['staff']['left_this_month']);
        $this->assertSame(1, $data['staff']['retiring_12_months']);
        $this->assertSame(1, $data['leave']['on_leave_today']);
        $this->assertSame(1, $data['contracts']['ending_30_days']);
        $this->assertEquals(2000000, $data['salary']['monthly_basic']);
        $this->assertSame(['month' => '2026-11', 'joined' => 1, 'left' => 1], end($data['movement']));
        $this->assertNull($data['payroll']);

        $viewer = User::factory()->create(['company_id' => $this->company->id]);
        $viewer->givePermissionTo('reports.view');
        $data = $this->actingAs($viewer)->getJson('/api/v1/reports/overview')->assertOk()->json('data');
        $this->assertSame(0, $data['staff']['active']);
        $this->assertNull($data['salary']);
        $this->actingAs($viewer)->get('/api/v1/reports/hr/joiners/csv')->assertForbidden();
        $this->actingAs($viewer)->get('/api/v1/reports/hr/joiners/pdf')->assertForbidden();

        $nobody = User::factory()->create(['company_id' => $this->company->id]);
        $this->actingAs($nobody)->getJson('/api/v1/reports/hr')->assertForbidden();
    }
}
