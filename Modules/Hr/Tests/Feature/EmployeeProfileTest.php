<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeDependant;
use App\Models\EmployeeQualification;
use App\Models\NatureOfContract;
use App\Models\ProfessionalLicence;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class EmployeeProfileTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private Employee $employee;

    private int $fullTime;

    private int $partTime;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-11 09:00:00');
        Storage::fake('local');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr']]]);
        setPermissionsTeamId($this->company->id);

        $this->employee = Employee::withoutCompanyScope()->where('company_id', $this->company->id)->firstOrFail();
        $this->base = "/api/v1/hr/employees/{$this->employee->id}";
        app(CompanyContext::class)->run($this->company, function () {
            $this->fullTime = NatureOfContract::create(['contract_name' => 'Specific time', 'code' => 'STC', 'employment_type' => 'FULL TIME'])->id;
            $this->partTime = NatureOfContract::create(['contract_name' => 'Part time', 'code' => 'PTC', 'employment_type' => 'part_time'])->id;
        });
    }

    private function contract(array $overrides = []): array
    {
        return $overrides + ['nature_of_contract_id' => $this->partTime, 'basic_salary' => 900000, 'start_date' => '2026-01-01', 'period' => 12, 'comment' => 'First contract'];
    }

    public function test_contract_lifecycle_follows_renewal_rules(): void
    {
        $this->actingAs($this->admin)->postJson("{$this->base}/contracts", $this->contract())
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.end_date', '2026-12-31')
            ->assertJsonPath('data.days_left', 50)
            ->assertJsonPath('data.history.0.action', 'created');
        $this->employee->refresh();
        $this->assertSame('part_time', $this->employee->emp_type);
        $this->assertSame('active', $this->employee->contract_status);

        $this->postJson("{$this->base}/contracts", $this->contract(['start_date' => '2026-12-15']))
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date' => 'must start after']);

        $renewal = $this->postJson("{$this->base}/contracts", $this->contract(['nature_of_contract_id' => $this->fullTime, 'start_date' => '2027-01-01', 'period' => 24]))
            ->assertCreated()->assertJsonPath('data.status', 'waiting')->json('data.id');
        $this->postJson("{$this->base}/contracts", $this->contract(['start_date' => '2029-01-01']))
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date' => 'already has a contract waiting']);

        $this->getJson($this->base)->assertOk()
            ->assertJsonPath('data.contract.status', 'active')
            ->assertJsonPath('data.waiting_contract.id', $renewal)
            ->assertJsonPath('data.counts.contracts', 2);

        $this->travelTo('2027-01-01 01:00:00');
        $this->artisan('contract:status')->assertSuccessful();
        $this->employee->refresh();
        $this->assertSame($renewal, (int) $this->employee->contract_id);
        $this->assertSame('full_time', $this->employee->emp_type);
        $this->assertSame('ended', EmployeeContract::where('id', '!=', $renewal)->value('status'));

        $this->postJson("{$this->base}/contracts", $this->contract(['start_date' => '2029-01-01']))
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date' => 'last 90 days']);
    }

    public function test_ending_a_contract_by_resignation_terminates_the_employee(): void
    {
        $id = $this->actingAs($this->admin)->postJson("{$this->base}/contracts", $this->contract(['start_date' => '2026-02-01']))->json('data.id');
        $this->postJson("{$this->base}/contracts", $this->contract(['start_date' => '2027-02-01']))->assertCreated();

        $end = ['reason' => 'resigned_30_days_notes', 'date' => '2026-11-30', 'comment' => 'Resigned with notice'];
        $this->postJson("{$this->base}/contracts/{$id}/end", $end + ['password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson("{$this->base}/contracts/{$id}/end", $end + ['password' => 'password'])
            ->assertOk()->assertJsonPath('data.status', 'resigned_30_days_notes')->assertJsonPath('data.is_active', false);

        $this->employee->refresh();
        $this->assertSame('terminated', $this->employee->employment_status);
        $this->assertSame('2026-11-30', $this->employee->termination_date->toDateString());
        $this->assertFalse(EmployeeContract::where('status', 'waiting')->exists());

        $this->postJson("{$this->base}/contracts", $this->contract(['start_date' => '2027-01-01']))
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date' => 'has left']);
    }

    public function test_revert_removes_only_the_latest_contract_and_restores_the_previous_one(): void
    {
        $this->actingAs($this->admin);
        $first = $this->postJson("{$this->base}/contracts", $this->contract())->json('data.id');
        $this->postJson("{$this->base}/contracts/{$first}/end", ['reason' => 'change_of_contract', 'date' => '2026-11-11', 'comment' => 'Move to full time', 'password' => 'password'])->assertOk();
        $second = $this->postJson("{$this->base}/contracts", $this->contract(['nature_of_contract_id' => $this->fullTime, 'start_date' => '2026-11-11']))
            ->assertJsonPath('data.status', 'active')->json('data.id');
        $this->assertSame('full_time', $this->employee->refresh()->emp_type);

        $this->postJson("{$this->base}/contracts/{$first}/revert", ['password' => 'password'])
            ->assertStatus(422)->assertJsonPath('message', 'Only the latest contract of an employee can be removed.');
        $this->assertSame('ST1', $this->employee->refresh()->employee_no, 'HRMS numbering: full-time contract gives an ST number');
        $this->postJson("{$this->base}/contracts/{$second}/revert", ['password' => 'password'])->assertOk()
            ->assertJsonPath('message', 'Contract removed. The employee number is back to PT1.');

        $this->assertNull(EmployeeContract::withTrashed()->find($second));
        $this->employee->refresh();
        $this->assertSame($first, (int) $this->employee->contract_id);
        $this->assertSame('change_of_contract', $this->employee->contract_status);
    }

    public function test_qualification_with_certificate_is_private_and_scoped(): void
    {
        $this->actingAs($this->admin);
        $res = $this->post("{$this->base}/qualifications", [
            'education_level' => 'degree', 'institution' => 'University of Dar es Salaam', 'program' => 'BSc Nursing',
            'end_year' => 2020, 'certificate' => UploadedFile::fake()->create('degree.pdf', 200, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.education_level_label', 'Degree')
            ->assertJsonPath('data.certificate.name', 'degree.pdf');

        $id = $res->json('data.id');
        $this->get($res->json('data.certificate.url'))->assertOk();
        Storage::disk('local')->assertExists("hr/{$this->company->id}/employees/{$this->employee->id}/qualifications/".basename(EmployeeQualification::find($id)->certificate));

        $this->post("{$this->base}/qualifications/{$id}", ['education_level' => 'masters', 'end_year' => 2024], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.program', null)->assertJsonPath('data.certificate.name', 'degree.pdf');

        [$other, $otherAdmin] = $this->createCompany('Beta', ['modules' => [['slug' => 'hr']]]);
        $this->actingAs($otherAdmin)->getJson("{$this->base}/qualifications")->assertNotFound();
        $otherEmployee = Employee::withoutCompanyScope()->where('company_id', $other->id)->value('id');
        $this->actingAs($otherAdmin)->get("/api/v1/hr/employees/{$otherEmployee}/qualifications/{$id}/file")->assertNotFound();
    }

    public function test_dependants_relationship_rules_and_single_next_of_kin(): void
    {
        $this->actingAs($this->admin);
        $add = fn (array $data) => $this->post("{$this->base}/dependants", $data + ['gender' => 'Male', 'address' => 'Mbezi, Dar es Salaam'], ['Accept' => 'application/json']);

        $spouse = $add(['fullname' => 'Juma Ali', 'relationship' => 'spouse', 'is_next_of_kin' => 'true'])->assertCreated()->json('data.id');
        $add(['fullname' => 'Other Spouse', 'relationship' => 'spouse'])->assertUnprocessable()->assertJsonValidationErrors(['relationship' => 'already has']);
        $add(['fullname' => 'Child One', 'relationship' => 'child', 'date_of_birth' => '2015-05-01'])->assertCreated()->assertJsonPath('data.age', 11);
        $child = $add(['fullname' => 'Child Two', 'relationship' => 'child', 'is_next_of_kin' => 1])->assertCreated()->json('data.id');

        $this->getJson("{$this->base}/dependants")->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $child)
            ->assertJsonPath('data.0.is_next_of_kin', true);
        $this->assertFalse(EmployeeDependant::find($spouse)->is_next_of_kin);
        $this->getJson($this->base)->assertJsonPath('data.counts.dependants', 3)->assertJsonPath('data.counts.next_of_kin', 1);
    }

    public function test_documents_licences_experience_and_summary(): void
    {
        $this->actingAs($this->admin);
        $this->post("{$this->base}/documents", ['file_type' => 'tin_certificate'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post("{$this->base}/documents", ['file_type' => 'id_copy', 'file' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post("{$this->base}/documents", ['file_type' => 'id_copy', 'file' => UploadedFile::fake()->image('id.png')], ['Accept' => 'application/json'])
            ->assertCreated();
        $doc = $this->post("{$this->base}/documents", ['file_type' => 'tin_certificate', 'file' => UploadedFile::fake()->create('tin.pdf', 50, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.file_type_label', 'TIN certificate')->json('data');
        $this->post("{$this->base}/documents/{$doc['id']}", ['file_type' => 'id_copy', 'description' => 'NIDA card'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.name', 'tin.pdf')->assertJsonPath('data.file_type', 'id_copy');

        $licenceType = app(CompanyContext::class)->run($this->company, fn () => ProfessionalLicence::create(['name' => 'Nursing licence'])->id);
        $this->post("{$this->base}/licences", ['professional_licence_id' => $licenceType, 'start_date' => '2024-01-01', 'end_date' => '2026-12-31'], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'expiring')->assertJsonPath('data.licence.name', 'Nursing licence');

        $this->postJson("{$this->base}/experiences", ['company_name' => 'Aga Khan Hospital', 'position' => 'Nurse', 'start_date' => '2020-01-01', 'end_date' => '2022-12-31'])
            ->assertCreated()->assertJsonPath('data.months', 35);

        $this->postJson("{$this->base}/contracts", $this->contract())->assertCreated();
        $this->getJson('/api/v1/hr/summary')->assertOk()
            ->assertJsonPath('data.contracts_ending', 1)
            ->assertJsonPath('data.licences_expiring', 1)
            ->assertJsonPath('data.without_contract', 0);
    }

    public function test_edit_details_photo_and_permissions(): void
    {
        $this->actingAs($this->admin);
        $current = $this->getJson($this->base)->json('data');
        $this->putJson($this->base, [
            'employee_no' => $current['employee_no'], 'fname' => 'Neema', 'lname' => 'Joseph', 'gender' => 'Female',
            'email' => 'Neema@Alpha.test', 'company_branch_id' => $current['branch']['id'], 'department_id' => $current['department']['id'],
            'job_position_id' => $current['position']['id'], 'emp_type' => 'full_time', 'employment_date' => '2026-01-01', 'employment_status' => 'confirmed',
        ])->assertOk()->assertJsonPath('data.name', 'Neema Joseph')->assertJsonPath('data.details.probation_start', null);
        $this->assertSame('neema@alpha.test', $this->admin->refresh()->email);

        $photo = $this->post("{$this->base}/photo", ['photo' => UploadedFile::fake()->image('me.jpg', 300, 300)], ['Accept' => 'application/json'])
            ->assertOk()->json('data.photo_url');
        $this->get($photo)->assertOk();

        $staff = User::factory()->create(['company_id' => $this->company->id]);
        $staff->assignRole('employee');
        $this->actingAs($staff)->postJson("{$this->base}/contracts", $this->contract())->assertForbidden();
        $this->actingAs($staff)->postJson("{$this->base}/experiences", ['company_name' => 'X', 'position' => 'Y', 'start_date' => '2020-01-01'])->assertForbidden();
    }

    public function test_contract_types_in_use_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin)->postJson("{$this->base}/contracts", $this->contract())->assertCreated();
        $this->deleteJson("/api/v1/hr/contract-types/{$this->partTime}")->assertStatus(422);
        $this->deleteJson("/api/v1/hr/contract-types/{$this->fullTime}")->assertNoContent();
        $this->getJson('/api/v1/hr/contract-types/active')->assertOk()->assertJsonCount(1, 'data');
    }
}
