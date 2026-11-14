<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\ContractRenewal;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmpSupervisor;
use App\Models\NatureOfContract;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class ContractRenewalTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private int $type;

    private Employee $employee;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-12 09:00:00');
        Storage::fake('local');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin']]]);
        setPermissionsTeamId($this->company->id);

        app(CompanyContext::class)->run($this->company, function () {
            $this->type = NatureOfContract::create(['contract_name' => 'Specific time', 'code' => 'STC', 'employment_type' => 'full_time'])->id;
            $this->user = User::factory()->create(['company_id' => $this->company->id]);
            $this->user->assignRole('employee');
            $this->employee = Employee::create(['employee_no' => 'ST5', 'fname' => 'Asha', 'lname' => 'Ali', 'gender' => 'Female',
                'emp_type' => 'full_time', 'employment_status' => 'confirmed', 'user_id' => $this->user->id, 'department_id' => Department::value('id')]);
        });
    }

    private function contract(string $start, string $end): EmployeeContract
    {
        return EmployeeContract::create(['employee_id' => $this->employee->id, 'nature_of_contract_id' => $this->type, 'contract_no' => 'ST5',
            'basic_salary' => 900000, 'period' => 12, 'start_date' => $start, 'end_date' => $end, 'status' => 'active', 'is_active' => true]);
    }

    private function hrStart(array $extra = [])
    {
        return $this->actingAs($this->admin)->postJson("/api/v1/hr/employees/{$this->employee->id}/renewals",
            $extra + ['nature_of_contract_id' => $this->type, 'period' => 12, 'basic_salary' => 1000000, 'comment' => 'Good work']);
    }

    public function test_hr_renewal_without_a_workflow_creates_the_waiting_contract(): void
    {
        $this->contract('2026-01-01', '2026-12-31');

        $this->hrStart()->assertCreated()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.source', 'hr');
        $waiting = $this->employee->waitingContract()->firstOrFail();
        $this->assertSame('2027-01-01', $waiting->start_date->toDateString());
        $this->assertSame('2027-12-31', $waiting->end_date->toDateString());
        $this->assertEquals(1000000, $waiting->basic_salary);

        $this->hrStart()->assertUnprocessable()->assertJsonValidationErrors(['renewal' => 'already in place']);

        $this->actingAs($this->user)->getJson('/api/v1/self/contract')->assertJsonCount(0, 'data.previous');
        EmployeeContract::create(['employee_id' => $this->employee->id, 'nature_of_contract_id' => $this->type, 'contract_no' => 'ST5', 'basic_salary' => 800000, 'period' => 12,
            'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'status' => 'ended', 'is_active' => false]);
        $this->getJson('/api/v1/self/contract')->assertJsonCount(1, 'data.previous')->assertJsonPath('data.previous.0.end_date', '2025-12-31')
            ->assertJsonPath('data.waiting_contract.start_date', '2027-01-01');
    }

    public function test_renewal_opens_only_in_the_last_90_days(): void
    {
        $this->contract('2026-06-01', '2027-05-31');
        $this->hrStart()->assertUnprocessable()->assertJsonValidationErrors(['renewal' => 'on 02 Mar 2027']);
        $this->actingAs($this->user)->getJson('/api/v1/self/contract')->assertOk()
            ->assertJsonPath('data.can_request', false)->assertJsonPath('data.contract.end_date', '2027-05-31');
    }

    public function test_renewal_is_allowed_from_exactly_90_days_before_the_end(): void
    {
        $contract = $this->contract('2026-01-01', '2027-02-11');
        $this->actingAs($this->user)->getJson('/api/v1/self/contract')->assertJsonPath('data.contract.days_left', 91)->assertJsonPath('data.can_request', false);
        $this->hrStart()->assertUnprocessable()->assertJsonValidationErrors(['renewal' => 'on 13 Nov 2026']);

        $contract->update(['end_date' => '2027-02-10']);
        $this->actingAs($this->user)->getJson('/api/v1/self/contract')->assertJsonPath('data.contract.days_left', 90)->assertJsonPath('data.can_request', true);
        $this->hrStart()->assertCreated();
    }

    public function test_employee_request_goes_through_approval_and_blocks_hr(): void
    {
        $this->contract('2026-01-01', '2026-12-31');
        $boss = User::factory()->create(['company_id' => $this->company->id]);
        $boss->assignRole('employee');
        $bossEmployee = Employee::withoutCompanyScope()->create(['company_id' => $this->company->id, 'employee_no' => 'B1', 'fname' => 'Boss', 'lname' => 'One', 'gender' => 'Male', 'user_id' => $boss->id]);
        EmpSupervisor::create(['employee_id' => $this->employee->id, 'supervisor_id' => $bossEmployee->id, 'from_date' => '2026-01-01', 'status' => 'active']);
        $this->actingAs($this->admin)->putJson('/api/v1/admin/workflows/contract-renewal', [
            'company_branch_id' => null, 'is_active' => true, 'steps' => [['name' => 'Supervisor', 'approver_type' => 'supervisor']],
        ])->assertOk();

        $letter = '<p>Dear HR Manager,</p><p>I request <strong>renewal</strong> of my contract because I want to continue serving in Nursing. '
            .'I have completed my duties well, I have good working relations with my team, and I would like to keep growing my skills here.</p>';
        $this->actingAs($this->user)->post('/api/v1/self/renewals', ['letter' => '<p><strong>'.str_repeat('Too short. ', 18).'</strong></p>'.str_repeat('<p></p>', 20), 'agreed' => 'true', 'language' => 'sw'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['letter' => 'too short']);
        $this->actingAs($this->user)->post('/api/v1/self/renewals', ['letter' => $letter, 'agreed' => 'false', 'language' => 'fr'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['agreed' => 'Tick the agreement', 'language']);
        $request = $this->actingAs($this->user)->post('/api/v1/self/renewals', [
            'letter' => $letter.'<script>alert(1)</script><p onclick="x()">Thanks</p>', 'agreed' => 'true', 'language' => 'sw', 'period' => 24, 'files' => [UploadedFile::fake()->create('timesheet.pdf', 80, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.letter', '<p><strong>YAH: OMBI LA KUONGEZEWA MKATABA WA AJIRA</strong></p>'.$letter.'<p>Thanks</p>'
                .'<p>Wako mtiifu,</p><p><strong>Asha Ali</strong><br>Namba ya mfanyakazi: ST5<br>Idara: '.Department::value('name').'</p>')
            ->assertJsonPath('data.letter_language', 'sw')->assertJsonPath('data.agreement', ContractRenewal::AGREEMENT)
            ->assertJsonPath('data.letter_text', fn ($t) => str_starts_with($t, "YAH: OMBI LA KUONGEZEWA MKATABA WA AJIRA\nDear HR Manager,\nI request renewal"))
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.source', 'employee')->assertJsonPath('data.waiting_for', 'Supervisor')
            ->assertJsonPath('data.files.0.name', 'timesheet.pdf')->assertJsonPath('data.can_withdraw', true);
        $id = $request->json('data.id');
        $this->get($request->json('data.files.0.url'))->assertOk();

        $this->hrStart()->assertUnprocessable()->assertJsonValidationErrors(['renewal' => 'already requested renewal']);
        $this->actingAs($this->admin)->getJson("/api/v1/hr/employees/{$this->employee->id}/renewals")->assertJsonPath('meta.blocker', fn ($v) => str_contains($v, 'already requested'));

        $workflow = ContractRenewal::find($id)->workflow;
        $this->actingAs($boss)->getJson('/api/v1/workflow/inbox')->assertJsonCount(1, 'data');
        $this->actingAs($boss)->postJson("/api/v1/workflow/requests/{$workflow->id}/approve")->assertOk();
        $this->assertSame('completed', ContractRenewal::find($id)->status, 'type and salary default to the current contract');
        $waiting = $this->employee->waitingContract()->firstOrFail();
        $this->assertSame(['2028-12-31', 900000.0], [$waiting->end_date->toDateString(), (float) $waiting->basic_salary]);
        $this->actingAs($this->admin)->getJson('/api/v1/hr/renewals?filter[status]=completed')->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.employee_no', 'ST5');
    }

    public function test_hr_renewal_blocks_the_employee_and_withdrawing_frees_it(): void
    {
        $this->contract('2026-01-01', '2026-12-31');
        $this->actingAs($this->admin)->putJson('/api/v1/admin/workflows/contract-renewal', [
            'company_branch_id' => null, 'is_active' => true, 'steps' => [['name' => 'Director', 'approver_type' => 'user', 'user_id' => $this->admin->id]],
        ])->assertOk();

        $id = $this->hrStart()->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->actingAs($this->user)->getJson('/api/v1/self/contract')->assertJsonPath('data.can_request', false)
            ->assertJsonPath('data.blocker', 'HR has already started your contract renewal.');
        $this->actingAs($this->user)->getJson('/api/v1/self/contract')->assertJsonPath('data.letter_templates.en.subject', 'RE: REQUEST FOR RENEWAL OF EMPLOYMENT CONTRACT')
            ->assertJsonPath('data.letter_templates.en.signature', ['Asha Ali', 'Employee number: ST5', 'Department: '.Department::value('name')])
            ->assertJsonPath('data.letter_templates.sw.closing', 'Wako mtiifu,');
        $this->actingAs($this->user)->post('/api/v1/self/renewals', ['letter' => str_repeat('Please renew my contract. ', 9), 'agreed' => 'true', 'language' => 'en'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['renewal' => 'HR has already started']);

        $this->actingAs($this->admin)->postJson("/api/v1/hr/renewals/{$id}/withdraw")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->actingAs($this->user)->getJson('/api/v1/self/contract')->assertJsonPath('data.can_request', true);
    }
}
