<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class StaffContactTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_phone_numbers_and_patient_ids_are_kept_in_one_list(): void
    {
        [$company, $admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr']]]);
        setPermissionsTeamId($company->id);
        [$asha, $juma, $staff] = app(CompanyContext::class)->run($company, function () use ($company) {
            $ward = Department::create(['name' => 'Ward']);
            $make = fn (string $no, ?string $phone) => Employee::create(['employee_no' => $no, 'fname' => $no, 'lname' => 'X', 'gender' => 'Female', 'department_id' => $ward->id,
                'emp_type' => 'full_time', 'employment_status' => 'confirmed', 'phone_number' => $phone]);

            return [$make('W1', '0712 345 678'), $make('W2', '12345'), tap(User::factory()->create(['company_id' => $company->id]))->assignRole('employee')];
        });
        $this->giveActiveContracts($company);
        $this->actingAs($admin);

        $this->getJson('/api/v1/hr/contacts')->assertOk()->assertJsonPath('meta.invalid_phone', 1)->assertJsonPath('meta.missing_patient_id', 3);
        $this->getJson('/api/v1/hr/contacts?filter=invalid_phone')->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_no', 'W2');

        $this->putJson("/api/v1/hr/employees/{$juma->id}/contacts", ['phone_number' => '0812345678'])->assertUnprocessable()->assertJsonValidationErrors(['phone_number' => '07, 06']);
        $this->putJson("/api/v1/hr/employees/{$juma->id}/contacts", ['phone_number' => '255 754 123 456', 'patient_id' => '34197'])->assertOk()
            ->assertJsonPath('data.phone_number', '255754123456')->assertJsonPath('data.phone_valid', true)->assertJsonPath('data.patient_id', '34197');
        $this->putJson("/api/v1/hr/employees/{$asha->id}/contacts", ['patient_id' => '34197'])->assertUnprocessable()->assertJsonValidationErrors(['patient_id' => 'belongs to W2 X']);
        $this->getJson("/api/v1/hr/employees/{$juma->id}")->assertJsonPath('data.patient_id', null);
        $this->putJson('/api/v1/hr/settings/general', ['hospital_patients' => true])->assertOk();
        $this->getJson("/api/v1/hr/employees/{$juma->id}")->assertJsonPath('data.patient_id', '34197');
        $this->putJson("/api/v1/hr/employees/{$juma->id}/contacts", ['patient_id' => ''])->assertOk()->assertJsonPath('data.patient_id', null);
        $this->getJson('/api/v1/hr/contacts')->assertJsonPath('meta.invalid_phone', 0);

        $this->actingAs($staff)->getJson('/api/v1/hr/contacts')->assertForbidden();
    }
}
