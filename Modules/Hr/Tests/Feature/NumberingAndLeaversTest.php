<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\CompanyBranch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContractEvent;
use App\Models\JobPosition;
use App\Models\MessageRecipient;
use App\Models\NatureOfContract;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Admin\Models\AccessChange;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class NumberingAndLeaversTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private int $fullTime;

    private int $partTime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-11 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin']]]);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->run($this->company, function () {
            $this->fullTime = NatureOfContract::create(['contract_name' => 'Specific time', 'code' => 'STC', 'employment_type' => 'full_time'])->id;
            $this->partTime = NatureOfContract::create(['contract_name' => 'Part time', 'code' => 'PTC', 'employment_type' => 'part_time'])->id;
        });
        $this->actingAs($this->admin);
    }

    private function format(string $name, array $segments, string $separator = '', bool $default = false): int
    {
        return $this->postJson('/api/v1/hr/number-formats', compact('name', 'segments', 'separator') + ['is_default' => $default])->assertCreated()->json('data.id');
    }

    private function register(array $overrides = []): array
    {
        $id = fn (string $model) => $model::withoutCompanyScope()->where('company_id', $this->company->id)->value('id');

        return $this->postJson('/api/v1/hr/employees', $overrides + [
            'employee_no' => 'EMP-0100', 'fname' => 'Juma', 'lname' => 'Ali', 'gender' => 'Male', 'email' => 'juma@alpha.test',
            'phone_number' => '0712 345 678', 'company_branch_id' => $id(CompanyBranch::class), 'department_id' => $id(Department::class),
            'job_position_id' => $id(JobPosition::class), 'emp_type' => 'full_time', 'employment_date' => '2026-01-01', 'employment_status' => 'confirmed',
        ])->assertCreated()->json('data');
    }

    private function contract(int $employee, int $type, string $start, int $period = 12): array
    {
        return $this->postJson("/api/v1/hr/employees/{$employee}/contracts", ['nature_of_contract_id' => $type, 'basic_salary' => 800000, 'start_date' => $start, 'period' => $period])
            ->assertCreated()->json('data');
    }

    public function test_number_formats_build_numbers_from_segments(): void
    {
        $staff = $this->format('Staff', [['type' => 'text', 'value' => 'ST'], ['type' => 'sequence', 'length' => 0, 'start_at' => 1]], default: true);
        $nurses = $this->format('Nursing interns', [
            ['type' => 'text', 'value' => 'INT/KH/NRS:'], ['type' => 'sequence', 'length' => 4, 'start_at' => 36], ['type' => 'text', 'value' => '/'], ['type' => 'year', 'length' => 2],
        ]);
        $dept = $this->format('By department', [['type' => 'department', 'value' => 'GEN'], ['type' => 'sequence', 'length' => 3]], '-');

        $this->register(['employee_no' => 'ST5']);
        $this->getJson('/api/v1/hr/employees/next-number')->assertJsonPath('data.employee_no', 'ST6');
        $this->getJson("/api/v1/hr/employees/next-number?format_id={$nurses}")->assertJsonPath('data.employee_no', 'INT/KH/NRS:0036/26');
        $hr = Department::withoutCompanyScope()->where('company_id', $this->company->id)->first();
        $hr->update(['short_name' => 'hr']);
        $this->getJson("/api/v1/hr/employees/next-number?format_id={$dept}&department_id={$hr->id}")->assertJsonPath('data.employee_no', 'HR-001');

        $this->postJson('/api/v1/hr/number-formats', ['name' => 'Bad', 'separator' => '', 'segments' => [['type' => 'sequence'], ['type' => 'sequence']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['segments' => 'exactly one running number']);

        $this->putJson("/api/v1/hr/contract-types/{$this->fullTime}", ['contract_name' => 'Specific time', 'code' => 'STC', 'employment_type' => 'full_time', 'employee_number_format_id' => $staff])
            ->assertOk()->assertJsonPath('data.number_format.name', 'Staff')->assertJsonPath('data.number_format.next_number', 'ST6');
        $this->getJson('/api/v1/hr/number-formats')->assertOk()->assertJsonPath('data.0.name', 'Staff')->assertJsonPath('data.0.contract_types.0.name', 'Specific time');
        $this->deleteJson("/api/v1/hr/number-formats/{$staff}")->assertStatus(422);
        $this->deleteJson("/api/v1/hr/number-formats/{$dept}")->assertNoContent();
    }

    public function test_contract_change_renumbers_and_revert_restores_the_old_number(): void
    {
        $st = $this->format('Staff', [['type' => 'text', 'value' => 'ST'], ['type' => 'sequence']]);
        $pt = $this->format('Part time', [['type' => 'text', 'value' => 'PT'], ['type' => 'sequence']]);
        NatureOfContract::withoutCompanyScope()->whereKey($this->fullTime)->update(['employee_number_format_id' => $st]);
        NatureOfContract::withoutCompanyScope()->whereKey($this->partTime)->update(['employee_number_format_id' => $pt]);

        $employee = $this->register()['id'];
        $first = $this->contract($employee, $this->partTime, '2026-11-01');
        $this->assertSame('EMP-0100', Employee::find($employee)->employee_no);

        $this->putJson('/api/v1/hr/settings/numbering', ['renumber_on_contract_change' => true])->assertOk();
        $end = fn (int $id) => $this->postJson("/api/v1/hr/employees/{$employee}/contracts/{$id}/end", ['reason' => 'change_of_contract', 'date' => '2026-11-11', 'comment' => 'Change', 'password' => 'password'])->assertOk();
        $end($first['id']);

        $partTime = $this->contract($employee, $this->partTime, '2026-11-11');
        $this->assertSame('PT1', Employee::find($employee)->employee_no);
        $this->assertSame('PT1', $partTime['contract_no']);
        $this->assertSame(['employee_no' => ['EMP-0100', 'PT1']], EmployeeContractEvent::where('employee_contract_id', $partTime['id'])->value('changes'));

        $end($partTime['id']);
        $fullTime = $this->contract($employee, $this->fullTime, '2026-11-11');
        $this->assertSame('ST1', Employee::find($employee)->employee_no);
        $this->assertSame('full_time', Employee::find($employee)->emp_type);

        $this->postJson("/api/v1/hr/employees/{$employee}/contracts/{$fullTime['id']}/revert", ['password' => 'password'])
            ->assertOk()->assertJsonPath('employee_no', 'PT1')->assertJsonPath('message', 'Contract removed. The employee number is back to PT1.');
        $this->assertSame('part_time', Employee::find($employee)->emp_type);
        $this->getJson('/api/v1/hr/employees/next-number?format_id='.$st)->assertJsonPath('data.employee_no', 'ST1');
    }

    public function test_revert_gives_a_fresh_number_when_the_old_one_was_taken(): void
    {
        $st = $this->format('Staff', [['type' => 'text', 'value' => 'ST'], ['type' => 'sequence']]);
        NatureOfContract::withoutCompanyScope()->whereKey($this->fullTime)->update(['employee_number_format_id' => $st]);
        $pt = $this->format('Part time', [['type' => 'text', 'value' => 'PT'], ['type' => 'sequence']]);
        NatureOfContract::withoutCompanyScope()->whereKey($this->partTime)->update(['employee_number_format_id' => $pt]);
        $this->putJson('/api/v1/hr/settings/numbering', ['renumber_on_contract_change' => true])->assertOk();

        $employee = $this->register(['employee_no' => 'PT7'])['id'];
        $contract = $this->contract($employee, $this->fullTime, '2026-11-11');
        $this->assertSame('ST1', Employee::find($employee)->employee_no);

        $this->register(['employee_no' => 'PT7', 'email' => 'other@alpha.test']);
        $this->postJson("/api/v1/hr/employees/{$employee}/contracts/{$contract['id']}/revert", ['password' => 'password'])
            ->assertOk()->assertJsonPath('employee_no', 'PT8');
    }

    public function test_leaver_account_is_deactivated_on_the_leaving_date(): void
    {
        Mail::fake();
        $employee = $this->register(['create_account' => true]);
        $contract = $this->contract($employee['id'], $this->fullTime, '2026-01-01');
        $user = User::find($employee['account']['id']);

        $this->postJson("/api/v1/hr/employees/{$employee['id']}/contracts/{$contract['id']}/end", [
            'reason' => 'resigned_30_days_notes', 'date' => '2026-12-11', 'comment' => 'Resigned', 'password' => 'password',
        ])->assertOk()->assertJsonPath('meta.account', 'scheduled');
        $this->assertTrue($user->refresh()->is_active);

        $this->travelTo('2026-12-11 01:00:00');
        $this->artisan('contract:status')->assertSuccessful();
        $this->assertFalse($user->refresh()->is_active);
        $change = AccessChange::where('user_id', $user->id)->sole();
        $this->assertSame(AccessChange::APPLIED, $change->status);
        $this->assertStringContainsString('Resignation, 30 days notice', $change->reason);
    }

    public function test_leaving_today_deactivates_at_once_but_keeps_the_only_admin(): void
    {
        Mail::fake();
        $employee = $this->register(['create_account' => true]);
        $contract = $this->contract($employee['id'], $this->fullTime, '2026-01-01');
        $this->postJson("/api/v1/hr/employees/{$employee['id']}/contracts/{$contract['id']}/end", [
            'reason' => 'terminated_24_hrs_notes', 'date' => '2026-11-11', 'comment' => 'Misconduct', 'password' => 'password',
        ])->assertOk()->assertJsonPath('meta.account', 'deactivated');
        $this->assertFalse(User::find($employee['account']['id'])->is_active);

        $adminEmployee = Employee::withoutCompanyScope()->where('user_id', $this->admin->id)->first();
        $adminContract = $this->contract($adminEmployee->id, $this->fullTime, '2026-01-01');
        $this->postJson("/api/v1/hr/employees/{$adminEmployee->id}/contracts/{$adminContract['id']}/end", [
            'reason' => 'retired', 'date' => '2026-11-11', 'comment' => 'Retired', 'password' => 'password',
        ])->assertOk()->assertJsonPath('meta.account', 'kept');
        $this->assertTrue($this->admin->refresh()->is_active);
    }

    public function test_contract_reminders_follow_the_hrms_rules(): void
    {
        $this->putJson('/api/v1/admin/sms-settings', ['is_enabled' => true, 'driver' => 'afyabox', 'sender_id' => 'ALPHA', 'contract_reminders' => true])->assertStatus(422);
        $this->putJson('/api/v1/admin/sms-settings', ['is_enabled' => true, 'driver' => 'log', 'sender_id' => 'ALPHA', 'contract_reminders' => true])
            ->assertOk()->assertJsonPath('data.drivers.1.configured', false);

        $employee = $this->register();
        $this->contract($employee['id'], $this->fullTime, '2026-02-01');

        $this->artisan('hr:contract-reminders')->expectsOutput('Contract reminders sent: 1')->assertSuccessful();
        $recipient = MessageRecipient::sole();
        $this->assertSame('255712345678', $recipient->recipient_address);
        $this->assertSame('Hi Juma Ali, contract ends on 2027-01-31 (81 days left). Please contact HR.', $recipient->individual_message);
        $this->assertSame(MessageRecipient::STATUS_SENT, $recipient->status);

        $this->artisan('hr:contract-reminders')->expectsOutput('Contract reminders sent: 0');
        $this->travelTo('2026-12-10 09:00:00');
        $this->artisan('hr:contract-reminders')->expectsOutput('Contract reminders sent: 1');

        $this->contract($employee['id'], $this->fullTime, '2027-02-01');
        $this->travelTo('2027-01-10 09:00:00');
        $this->artisan('hr:contract-reminders')->expectsOutput('Contract reminders sent: 0');
    }
}
