<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\NatureOfContract;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class SupervisorDeductionTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'payroll']]]);
        setPermissionsTeamId($this->company->id);
        $this->actingAs($this->admin);
        $this->giveActiveContracts($this->company);
    }

    private function employee(string $no): Employee
    {
        return Employee::withoutCompanyScope()->create(['company_id' => $this->company->id, 'employee_no' => $no, 'fname' => $no, 'lname' => 'Test', 'gender' => 'Male', 'employment_status' => 'confirmed']);
    }

    public function test_one_active_supervisor_with_history_and_no_loops(): void
    {
        [$a, $b, $c] = [$this->employee('A'), $this->employee('B'), $this->employee('C')];
        $url = fn (Employee $e) => "/api/v1/hr/employees/{$e->id}/supervisors";

        $this->postJson($url($c), ['supervisor_id' => $c->id, 'from_date' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['supervisor_id' => 'themselves']);
        $this->postJson($url($c), ['supervisor_id' => $b->id, 'from_date' => '2026-01-01'])->assertCreated();
        $this->postJson($url($b), ['supervisor_id' => $a->id, 'from_date' => '2026-01-01'])->assertCreated();
        $this->postJson($url($a), ['supervisor_id' => $c->id, 'from_date' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['supervisor_id' => 'directly or through others']);

        $this->postJson($url($c), ['supervisor_id' => $a->id, 'from_date' => '2026-06-01', 'reason' => 'Reorganised'])->assertCreated();
        $this->getJson($url($c))->assertOk()
            ->assertJsonCount(2, 'data.history')
            ->assertJsonPath('data.history.0.supervisor.employee_no', 'A')
            ->assertJsonPath('data.history.0.active', true)
            ->assertJsonPath('data.history.1.to_date', '2026-05-31');
        $this->getJson($url($a))->assertJsonCount(2, 'data.team');
        $this->getJson("/api/v1/hr/employees/{$c->id}")->assertJsonPath('data.supervisor.employee_no', 'A');
        $this->getJson("/api/v1/hr/employees/{$a->id}")->assertJsonPath('data.counts.team', 2);

        $this->postJson("{$url($c)}/end", ['date' => '2026-07-01'])->assertOk();
        $this->getJson("/api/v1/hr/employees/{$c->id}")->assertJsonPath('data.supervisor', null);
    }

    public function test_employees_report_to_the_head_of_department_by_default(): void
    {
        $nursing = $this->postJson('/api/v1/hr/structure/departments', ['name' => 'Nursing'])->json('data.id');
        $make = fn (string $no) => Employee::withoutCompanyScope()->create(['company_id' => $this->company->id, 'employee_no' => $no, 'fname' => $no, 'lname' => 'Test', 'gender' => 'Male', 'employment_status' => 'confirmed', 'department_id' => $nursing]);
        [$head, $nurse, $nurse2] = [$make('HEAD'), $make('N1'), $make('N2')];
        $this->giveActiveContracts($this->company);
        $this->postJson("/api/v1/hr/structure/departments/{$nursing}/head", ['employee_id' => $head->id])->assertOk();

        $this->getJson("/api/v1/hr/employees/{$nurse->id}/supervisors")->assertOk()
            ->assertJsonPath('data.current.employee_no', 'HEAD')->assertJsonPath('data.current.source', 'department_head')
            ->assertJsonPath('data.current.title', 'Head of Nursing')->assertJsonPath('data.history', []);
        $this->getJson("/api/v1/hr/employees/{$head->id}/supervisors")->assertJsonPath('data.current', null)->assertJsonCount(2, 'data.team');
        $this->getJson("/api/v1/hr/employees/{$nurse->id}")->assertJsonPath('data.supervisor.source', 'department_head');

        $this->postJson("/api/v1/hr/employees/{$head->id}/supervisors", ['supervisor_id' => $nurse->id, 'from_date' => '2026-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors(['supervisor_id' => 'directly or through others']);

        $this->postJson("/api/v1/hr/employees/{$nurse2->id}/supervisors", ['supervisor_id' => $nurse->id, 'from_date' => '2026-01-01'])->assertCreated();
        $this->getJson("/api/v1/hr/employees/{$nurse2->id}/supervisors")->assertJsonPath('data.current.employee_no', 'N1')->assertJsonPath('data.current.source', 'assigned')
            ->assertJsonPath('data.current.title', 'N1 Test');
        $this->getJson("/api/v1/hr/employees/{$head->id}/supervisors")->assertJsonCount(1, 'data.team')->assertJsonPath('data.team.0.employee_no', 'N1');
        $this->getJson("/api/v1/hr/employees/{$head->id}")->assertJsonPath('data.counts.team', 1);
    }

    public function test_statutory_memberships_follow_hrms_rules(): void
    {
        $e = $this->employee('E1');
        $other = $this->employee('E2');
        $type = app(CompanyContext::class)->run($this->company, fn () => NatureOfContract::create(['contract_name' => 'Staff', 'code' => 'ST', 'employment_type' => 'full_time'])->id);
        EmployeeContract::create(['employee_id' => $e->id, 'nature_of_contract_id' => $type, 'basic_salary' => 1000000, 'period' => 12, 'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(11), 'status' => 'active', 'is_active' => true]);
        $base = "/api/v1/hr/employees/{$e->id}/deductions";

        $this->putJson("{$base}/nssf", ['value' => 10])->assertUnprocessable()->assertJsonValidationErrors('contribution_no');
        $this->putJson("{$base}/nssf", ['contribution_no' => 'NS-1', 'value' => 25])->assertUnprocessable()->assertJsonValidationErrors(['value' => '20%']);
        $this->putJson("{$base}/nssf", ['contribution_no' => 'NS-1', 'value' => 10])->assertOk();
        $this->putJson("/api/v1/hr/employees/{$other->id}/deductions/nssf", ['contribution_no' => 'NS-1', 'value' => 10])->assertUnprocessable()->assertJsonValidationErrors(['contribution_no' => 'belongs to']);
        $this->putJson("{$base}/nhif", ['contribution_no' => 'NH-1', 'value' => 3])->assertOk();
        $this->putJson("{$base}/heslb", ['value' => 15])->assertOk();
        $this->putJson("{$base}/nic", ['value' => 25000])->assertOk();
        $this->putJson("{$base}/fund", ['value' => 5000, 'is_active' => false])->assertOk();
        $this->putJson("{$base}/unknown", ['value' => 1])->assertNotFound();

        $rows = collect($this->getJson($base)->assertOk()->assertJsonPath('meta.basic_salary', 1000000)->json('data'))->keyBy('scheme');
        $this->assertSame(['employee' => 100000, 'employer' => 100000], $rows['nssf']['estimate']);
        $this->assertEquals(3, $rows['nhif']['employer_value']);
        $this->assertSame(['employee' => 150000, 'employer' => 0], $rows['heslb']['estimate']);
        $this->assertSame(['employee' => 25000, 'employer' => 0], $rows['nic']['estimate']);
        $this->assertNull($rows['fund']['estimate']);
        $this->assertFalse($rows['tughe']['member']);
        $this->assertSame('NS-1', $e->refresh()->nssf_no);

        $this->deleteJson("{$base}/heslb")->assertNoContent();
        $this->assertFalse(collect($this->getJson($base)->json('data'))->keyBy('scheme')['heslb']['member']);
    }
}
