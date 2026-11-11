<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeePlacement;
use App\Models\NatureOfContract;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\SingleDeviceSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Hr\Support\EmployeeLoginCheck;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class LoginContractTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private int $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-11 09:00:00');
        [$this->company] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr']]]);
        $this->withHeader('Referer', 'http://localhost:4200');
        $this->type = app(CompanyContext::class)->run($this->company, fn () => NatureOfContract::create(['contract_name' => 'Staff', 'code' => 'ST', 'employment_type' => 'full_time'])->id);
    }

    private function staff(string $username, string $empType = 'full_time', bool $employee = true): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'username' => $username, 'password' => Hash::make('password'), 'is_active' => true]);
        setPermissionsTeamId($this->company->id);
        $user->assignRole('employee');
        if ($employee) {
            Employee::withoutCompanyScope()->create([
                'company_id' => $this->company->id, 'user_id' => $user->id, 'employee_no' => strtoupper($username), 'fname' => $username, 'lname' => 'Test',
                'gender' => 'Male', 'emp_type' => $empType, 'employment_status' => 'confirmed',
            ]);
        }

        return $user;
    }

    private function contract(User $user, string $start, string $end, string $status = 'active'): void
    {
        EmployeeContract::create([
            'employee_id' => $user->employee->id, 'nature_of_contract_id' => $this->type, 'basic_salary' => 1, 'period' => 12,
            'start_date' => $start, 'end_date' => $end, 'status' => $status, 'is_active' => $status === 'active',
        ]);
    }

    private function login(string $username)
    {
        return $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => 'password']);
    }

    public function test_staff_need_an_active_contract_to_log_in(): void
    {
        $this->staff('nocontract');
        $this->login('nocontract')->assertUnprocessable()->assertJsonValidationErrors(['username' => "You don't have an active contract"]);

        $this->staff('noemployee', employee: false);
        $this->login('noemployee')->assertUnprocessable()->assertJsonPath('errors.username.0', EmployeeLoginCheck::NO_EMPLOYEE);

        $ended = $this->staff('ended');
        $this->contract($ended, '2025-01-01', '2025-12-31', 'ended');
        $this->login('ended')->assertUnprocessable();

        $active = $this->staff('active');
        $this->contract($active, '2026-01-01', '2026-12-31');
        $this->login('active')->assertOk();
    }

    public function test_renewal_starting_today_and_interns_can_log_in(): void
    {
        $renewed = $this->staff('renewed');
        $this->contract($renewed, '2026-11-11', '2027-11-10', 'waiting');
        $this->login('renewed')->assertOk();
        $this->assertTrue($renewed->employee->contracts()->sole()->is_active);

        $intern = $this->staff('intern', 'internship');
        EmployeePlacement::create(['employee_id' => $intern->employee->id, 'type' => 'internship', 'year' => 2026, 'period' => 6, 'start_date' => '2026-07-01', 'end_date' => '2026-12-31', 'status' => 'active']);
        $this->post('/api/v1/auth/logout');
        $this->login('intern')->assertOk();
    }

    public function test_company_admin_and_companies_without_hr_are_not_checked(): void
    {
        $this->login('hr_alpha')->assertOk();

        [$beta] = $this->createCompany('Beta');
        $user = User::factory()->create(['company_id' => $beta->id, 'username' => 'beta_staff', 'password' => Hash::make('password'), 'is_active' => true]);
        $this->post('/api/v1/auth/logout');
        $this->login('beta_staff')->assertOk();
    }

    public function test_open_session_ends_when_the_contract_ends(): void
    {
        $user = $this->staff('expiring');
        $this->contract($user, '2026-01-01', '2026-11-11');
        $this->actingAs($user)->withSession(['last_activity' => time()])->getJson('/api/v1/me')->assertOk();

        $this->travelTo('2026-11-12 08:00:00');
        app(SingleDeviceSessionManager::class)->clearSessionRegistration($user->id);
        $this->actingAs($user)->withSession(['last_activity' => time(), 'login_checked_at' => time() - 60])->getJson('/api/v1/me')->assertOk();
        app(SingleDeviceSessionManager::class)->clearSessionRegistration($user->id);
        $this->actingAs($user)->withSession(['last_activity' => time(), 'login_checked_at' => time() - 16 * 60])->getJson('/api/v1/me')
            ->assertUnauthorized()->assertJsonPath('code', 'access_ended')->assertJsonPath('message', EmployeeLoginCheck::NO_CONTRACT);
    }
}
