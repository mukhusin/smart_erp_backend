<?php

namespace Modules\Workflow\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmpSupervisor;
use App\Models\HeadOfDepartment;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\StepApprovers;
use Modules\Workflow\Services\WorkflowEngine;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class HeadRaisedRequestTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_request_raised_by_head_goes_to_their_director_else_director_general(): void
    {
        [$company] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr']]]);
        setPermissionsTeamId($company->id);
        $engine = app(WorkflowEngine::class);

        app(CompanyContext::class)->run($company, function () use ($company, $engine) {
            $dept = Department::create(['name' => 'Finance', 'is_active' => true]);
            $person = function (string $no) use ($company, $dept) {
                $user = User::factory()->create(['company_id' => $company->id]);
                $employee = Employee::create(['employee_no' => $no, 'fname' => $no, 'lname' => 'X', 'gender' => 'Male', 'employment_status' => 'confirmed',
                    'department_id' => $dept->id, 'user_id' => $user->id]);

                return [$user, $employee];
            };
            [$head, $headEmp] = $person('HOD');
            [$deputy, $deputyEmp] = $person('DEP');
            [$clerk, $clerkEmp] = $person('CLK');
            [$director, $directorEmp] = $person('DF');
            [$dg] = $person('DG');
            $dg->assignRole('director-general');
            HeadOfDepartment::create(['employee_id' => $headEmp->id, 'department_id' => $dept->id, 'level' => 'hod', 'is_active' => true]);
            HeadOfDepartment::create(['employee_id' => $deputyEmp->id, 'department_id' => $dept->id, 'level' => 'hod', 'is_deputy' => true, 'is_active' => true]);

            $instance = function (Employee $employee, User $by) use ($dept) {
                $i = WorkflowInstance::create(['process' => 'leave', 'subject_type' => 'x', 'subject_id' => 1, 'department_id' => $dept->id,
                    'employee_id' => $employee->id, 'status' => WorkflowInstance::PENDING, 'current_step_order' => 1, 'initiated_by' => $by->id]);
                $i->steps()->create(['step_order' => 1, 'name' => 'HOD', 'approver_type' => 'department_head', 'status' => 'pending', 'allow_send_back' => false, 'same_branch' => false]);

                return $i->fresh();
            };

            $fromClerk = $instance($clerkEmp, $clerk);
            $this->assertTrue($engine->canAct($head, $fromClerk));
            $this->assertTrue($engine->canAct($deputy, $fromClerk));
            $this->assertFalse($engine->canAct($dg, $fromClerk));

            $fromHead = $instance($headEmp, $head);
            $this->assertFalse($engine->canAct($deputy, $fromHead));
            $this->assertTrue($engine->canAct($dg, $fromHead));
            $this->assertSame([$dg->id], app(StepApprovers::class)->for($fromHead, $fromHead->steps->first())->pluck('id')->all());

            EmpSupervisor::create(['employee_id' => $headEmp->id, 'supervisor_id' => $directorEmp->id, 'from_date' => now(), 'status' => 'active']);
            $this->assertTrue($engine->canAct($director, $fromHead->fresh()));
            $this->assertFalse($engine->canAct($dg, $fromHead->fresh()));
        });
    }
}
