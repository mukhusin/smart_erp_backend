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

    public function test_contacts_are_updated_in_bulk_from_a_csv(): void
    {
        [$company, $admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr']]]);
        setPermissionsTeamId($company->id);
        app(CompanyContext::class)->run($company, function () {
            $ward = Department::create(['name' => 'Ward']);
            foreach ([['W1', '0712345678'], ['W2', null], ['W3', '0713000000']] as [$no, $phone]) {
                Employee::create(['employee_no' => $no, 'fname' => $no, 'lname' => 'X', 'gender' => 'Female', 'department_id' => $ward->id,
                    'emp_type' => 'full_time', 'employment_status' => 'confirmed', 'phone_number' => $phone]);
            }
        });
        $this->giveActiveContracts($company);
        $this->actingAs($admin);
        $this->putJson('/api/v1/hr/settings/general', ['hospital_patients' => true])->assertOk();

        $csv = $this->get('/api/v1/hr/contacts/template')->assertOk()->getContent();
        $this->assertStringContainsString('employee_no,name,phone_number,patient_id', $csv);
        $this->assertStringContainsString("W1,\"W1 X\",0712345678,\nW2,\"W2 X\",,\n", $csv);

        $file = fn (string $body) => \Illuminate\Http\UploadedFile::fake()->createWithContent('contacts.csv', "employee_no,name,phone_number,patient_id\n".$body);
        $bad = $this->postJson('/api/v1/hr/contacts/import', ['file' => $file("W1,,0712345678,\nW2,,0812,P1\nW9,,0712000000,\nW3,,0755 111 222,\n")])->assertOk();
        $this->assertSame([1, 1, 2], [$bad->json('changed'), $bad->json('unchanged'), $bad->json('invalid')]);
        $this->postJson('/api/v1/hr/contacts/import', ['file' => $file("W2,,0812,\n"), 'commit' => true])->assertUnprocessable();

        $ok = $this->postJson('/api/v1/hr/contacts/import', ['file' => $file("W2,,255 754 000 111,P7\nW3,,0755 111 222,\n"), 'commit' => true])->assertOk();
        $this->assertSame(2, $ok->json('saved'));
        $this->assertSame(['Phone — → 255754000111', 'Patient ID — → P7'], $ok->json('rows.0.changes'));
        $rows = collect($this->getJson('/api/v1/hr/contacts')->json('data'))->keyBy('employee_no');
        $this->assertSame(['255754000111', 'P7', '0755111222'], [$rows['W2']['phone_number'], $rows['W2']['patient_id'], $rows['W3']['phone_number']]);
    }
}
