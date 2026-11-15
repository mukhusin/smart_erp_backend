<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\JobPosition;
use App\Models\NatureOfContract;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Hr\Support\ContractDocument;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class ContractDocumentTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    public function test_contract_prints_from_the_type_template(): void
    {
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'self-service']]]);
        setPermissionsTeamId($this->company->id);
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $user->assignRole('employee');
        [$type, $employee, $contract, $other] = app(CompanyContext::class)->run($this->company, function () use ($user) {
            $ward = Department::create(['name' => 'Ward']);
            $nurse = JobPosition::create(['title' => 'Nurse', 'department_id' => $ward->id, 'is_active' => true, 'description' => 'Care for patients']);
            $type = NatureOfContract::create(['contract_name' => 'Specific time', 'code' => 'STC', 'employment_type' => 'full_time']);
            $make = fn ($no, $userId) => Employee::create(['employee_no' => $no, 'emp_title' => 'Ms', 'fname' => 'Asha', 'lname' => '<Juma>', 'gender' => 'Female', 'department_id' => $ward->id,
                'job_position_id' => $nurse->id, 'user_id' => $userId, 'emp_type' => 'full_time', 'employment_status' => 'confirmed', 'employment_date' => '2026-01-01']);
            $e = $make('ST1', $user->id);
            $c = EmployeeContract::create(['employee_id' => $e->id, 'nature_of_contract_id' => $type->id, 'contract_no' => 'C-1', 'basic_salary' => 910000, 'period' => 12,
                'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'active', 'is_active' => true]);
            $o = $make('ST2', null);
            $oc = EmployeeContract::create(['employee_id' => $o->id, 'nature_of_contract_id' => $type->id, 'contract_no' => 'C-2', 'basic_salary' => 500000, 'period' => 12,
                'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'active', 'is_active' => true]);

            return [$type, $e, $c, $oc];
        });

        $this->actingAs($this->admin)->getJson('/api/v1/hr/contract-types/placeholders')->assertOk()
            ->assertJsonFragment(['key' => 'employee_name', 'label' => 'Full name with title']);
        $this->putJson("/api/v1/hr/contract-types/{$type->id}", ['contract_name' => 'Specific time', 'code' => 'STC', 'employment_type' => 'full_time',
            'template_content' => '<p>{company_name} employs <strong>{employee_name}</strong> as {position} from {start_date} for {basic_salary}. {unknown}</p><script>alert(1)</script>'])
            ->assertOk()->assertJsonPath('data.template_content', '<p>{company_name} employs <strong>{employee_name}</strong> as {position} from {start_date} for {basic_salary}. {unknown}</p>');

        $html = app(CompanyContext::class)->run($this->company, fn () => app(ContractDocument::class)->render($contract->fresh()));
        $this->assertSame('<p>Alpha employs <strong>Ms Asha &lt;Juma&gt;</strong> as Nurse from 1 January 2026 for TZS 910,000.00. {unknown}</p>', $html);

        $this->get("/api/v1/hr/employees/{$employee->id}/contracts/{$contract->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get("/api/v1/hr/employees/{$employee->id}/contracts/{$other->id}/pdf")->assertNotFound();
        $this->actingAs($user)->get("/api/v1/self/contracts/{$contract->id}/pdf")->assertOk();
        $this->actingAs($user)->get("/api/v1/self/contracts/{$other->id}/pdf")->assertNotFound();
        $this->actingAs($user)->get("/api/v1/hr/employees/{$employee->id}/contracts/{$contract->id}/pdf")->assertForbidden();

        $type->update(['template_content' => null]);
        $this->assertStringContainsString('to the post of <u>Nurse</u>', app(CompanyContext::class)->run($this->company, fn () => app(ContractDocument::class)->render($contract->fresh())));
    }
}
