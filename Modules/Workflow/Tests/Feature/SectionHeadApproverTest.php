<?php

namespace Modules\Workflow\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\HeadOfDepartment;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class SectionHeadApproverTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_section_head_approves_and_department_head_covers_a_section_without_head(): void
    {
        [$company] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr']]]);
        $engine = app(WorkflowEngine::class);

        app(CompanyContext::class)->run($company, function () use ($company, $engine) {
            $dept = Department::create(['name' => 'Nursing', 'is_active' => true]);
            $ward = Department::create(['name' => 'Ward 1', 'parent_id' => $dept->id, 'is_active' => true]);
            $person = function (string $no, ?int $section) use ($company, $dept) {
                $user = User::factory()->create(['company_id' => $company->id]);
                Employee::create(['employee_no' => $no, 'fname' => $no, 'lname' => 'X', 'gender' => 'Male', 'employment_status' => 'confirmed',
                    'department_id' => $dept->id, 'section_id' => $section, 'user_id' => $user->id]);

                return $user;
            };
            [$nurse, $hos, $hod] = [$person('N1', $ward->id), $person('HOS', $ward->id), $person('HOD', null)];
            HeadOfDepartment::create(['employee_id' => Employee::where('employee_no', 'HOS')->value('id'), 'department_id' => $ward->id, 'level' => 'hos', 'is_active' => true]);
            HeadOfDepartment::create(['employee_id' => Employee::where('employee_no', 'HOD')->value('id'), 'department_id' => $dept->id, 'level' => 'hod', 'is_active' => true]);

            $instance = function (Employee $employee) use ($nurse) {
                $i = WorkflowInstance::create(['process' => 'overtime-claim', 'subject_type' => 'x', 'subject_id' => 1, 'department_id' => $employee->department_id,
                    'employee_id' => $employee->id, 'status' => WorkflowInstance::PENDING, 'current_step_order' => 1, 'initiated_by' => $nurse->id]);
                $i->steps()->create(['step_order' => 1, 'name' => 'HOS', 'approver_type' => 'section_head', 'status' => 'pending', 'allow_send_back' => false, 'same_branch' => false]);

                return $i->fresh();
            };

            $inWard = $instance(Employee::where('employee_no', 'N1')->first());
            $this->assertTrue($engine->canAct($hos, $inWard));
            $this->assertFalse($engine->canAct($hod, $inWard));

            HeadOfDepartment::where('level', 'hos')->update(['is_active' => false]);
            $this->assertTrue($engine->canAct($hod, $inWard->fresh()));
            $this->assertFalse($engine->canAct($hos, $inWard->fresh()));
        });
    }
}
