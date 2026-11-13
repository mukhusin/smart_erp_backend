<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeQualification;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Workflow\Models\WorkflowInstance;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class SelfProfileTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $hr;

    private User $me;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        [$this->company, $this->hr] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'self-service']]]);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->run($this->company, function () {
            $this->hr->assignRole('hr-officer');
            $this->me = User::factory()->create(['company_id' => $this->company->id]);
            $this->me->assignRole('employee');
            $this->employee = Employee::create(['employee_no' => 'ST9', 'fname' => 'Asha', 'lname' => 'Ali', 'gender' => 'Female', 'emp_type' => 'full_time',
                'employment_status' => 'confirmed', 'user_id' => $this->me->id, 'department_id' => Department::value('id'), 'date_of_birth' => '1990-01-01']);
        });
    }

    private function qualification(): array
    {
        return ['education_level' => 'degree', 'program' => 'BSc Nursing', 'end_year' => 2015, 'certificate' => UploadedFile::fake()->create('cert.pdf', 50, 'application/pdf')];
    }

    public function test_sees_profile_and_edits_only_contact(): void
    {
        $this->actingAs($this->me)->getJson('/api/v1/self/profile')->assertOk()
            ->assertJsonPath('data.personal.fname', 'Asha')->assertJsonPath('data.personal.date_of_birth', '1990-01-01')->assertJsonPath('data.sections.qualifications', []);

        $this->putJson('/api/v1/self/profile/contact', ['phone_number' => '0712 000 111', 'home_address' => 'Mikocheni', 'fname' => 'Changed', 'date_of_birth' => '2000-01-01'])
            ->assertOk()->assertJsonPath('data.phone_number', '0712 000 111');
        $fresh = $this->employee->fresh();
        $this->assertSame('Asha', $fresh->fname);
        $this->assertSame('1990-01-01', $fresh->date_of_birth->toDateString());
        $this->assertSame('Mikocheni', $fresh->home_address);
    }

    public function test_added_records_wait_for_hr_approval(): void
    {
        $id = $this->actingAs($this->me)->post('/api/v1/self/profile/qualifications', $this->qualification(), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.approval_status', 'pending')->json('data.id');
        $this->getJson('/api/v1/self/profile')->assertJsonPath('data.sections.qualifications.0.certificate.url', "/api/v1/self/profile/qualifications/{$id}/file");
        $this->get("/api/v1/self/profile/qualifications/{$id}/file")->assertOk();

        $this->actingAs($this->hr)->getJson("/api/v1/hr/employees/{$this->employee->id}/qualifications")->assertJsonPath('data.0.approval_status', 'pending');
        $workflow = WorkflowInstance::where('subject_id', $id)->first();
        $this->actingAs($this->hr)->getJson("/api/v1/workflow/requests/{$workflow->id}")->assertJsonPath('data.lines.0', 'Degree');
        $this->postJson("/api/v1/workflow/requests/{$workflow->id}/approve")->assertOk();
        $this->assertSame('approved', EmployeeQualification::find($id)->status);

        $this->actingAs($this->me)->deleteJson("/api/v1/self/profile/qualifications/{$id}")->assertUnprocessable();
    }

    public function test_withdraw_pending_and_remove_rejected(): void
    {
        $first = $this->actingAs($this->me)->post('/api/v1/self/profile/qualifications', $this->qualification(), ['Accept' => 'application/json'])->json('data.id');
        $this->deleteJson("/api/v1/self/profile/qualifications/{$first}")->assertNoContent();
        $this->assertNull(EmployeeQualification::find($first));
        $this->assertSame(WorkflowInstance::CANCELLED, WorkflowInstance::where('subject_id', $first)->value('status'));

        $second = $this->post('/api/v1/self/profile/experiences', ['company_name' => 'Muhimbili', 'position' => 'Nurse', 'start_date' => '2016-01-01'], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $workflow = WorkflowInstance::where('subject_id', $second)->where('process', 'profile-update')->latest('id')->first();
        $this->actingAs($this->hr)->postJson("/api/v1/workflow/requests/{$workflow->id}/reject", ['comment' => 'Attach the letter'])->assertOk();
        $this->actingAs($this->me)->getJson('/api/v1/self/profile')->assertJsonPath('data.sections.experiences.0.approval_status', 'rejected')
            ->assertJsonPath('data.sections.experiences.0.decision_comment', 'Attach the letter');
        $this->deleteJson("/api/v1/self/profile/experiences/{$second}")->assertNoContent();

        $other = User::factory()->create(['company_id' => $this->company->id]);
        $other->assignRole('employee');
        $id = $this->post('/api/v1/self/profile/qualifications', $this->qualification(), ['Accept' => 'application/json'])->json('data.id');
        $this->actingAs($other)->get("/api/v1/self/profile/qualifications/{$id}/file")->assertNotFound();
    }
}
