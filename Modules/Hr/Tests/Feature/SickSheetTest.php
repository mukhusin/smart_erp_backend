<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeTimetable;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Workflow\Models\WorkflowTemplate;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class SickSheetTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_sick_sheets_from_employee_and_hr_show_on_the_roster(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-11-16 09:00:00');
        [$company, $admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'self-service']]]);
        setPermissionsTeamId($company->id);
        [$user, $asha] = app(CompanyContext::class)->run($company, function () use ($company) {
            $user = tap(User::factory()->create(['company_id' => $company->id]))->assignRole('employee');
            $asha = Employee::create(['employee_no' => 'W1', 'fname' => 'Asha', 'lname' => 'X', 'gender' => 'Female', 'department_id' => Department::value('id'),
                'emp_type' => 'full_time', 'employment_status' => 'confirmed', 'user_id' => $user->id]);
            WorkflowTemplate::where('process', 'excuse-of-duty')->delete();

            return [$user, $asha];
        });
        $sheet = fn () => UploadedFile::fake()->create('sheet.pdf', 50, 'application/pdf');

        $this->actingAs($user)->post('/api/v1/self/sick-sheets', ['start_date' => '2026-11-16', 'end_date' => '2026-11-17'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['file' => 'Attach the sick sheet']);
        $id = $this->post('/api/v1/self/sick-sheets', ['start_date' => '2026-11-16', 'end_date' => '2026-11-17', 'doctor_name' => 'Dr Juma', 'file' => $sheet()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.number_of_days', 2)->assertJsonPath('data.source', 'employee')->json('data.id');
        $days = collect($this->getJson('/api/v1/self/duties?month=2026-11')->json('data'))->keyBy('date');
        $this->assertSame(['excused', 'ED', 'Excuse of duty'], [$days['2026-11-16']['kind'], $days['2026-11-16']['code'], $days['2026-11-16']['label']]);
        $this->getJson("/api/v1/self/sick-sheets/{$id}/file")->assertOk();

        $this->actingAs($admin)->getJson('/api/v1/hr/sick-sheets?month=2026-11')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.name', 'Asha X');
        $this->postJson("/api/v1/hr/employees/{$asha->id}/sick-sheets", ['start_date' => '2026-11-17', 'end_date' => '2026-11-18'])->assertUnprocessable()->assertJsonValidationErrors(['start_date' => 'already a sick sheet']);
        $hr = $this->postJson("/api/v1/hr/employees/{$asha->id}/sick-sheets", ['start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'hospital_name' => 'Kairuki'])
            ->assertCreated()->assertJsonPath('data.source', 'hr')->assertJsonPath('data.status', 'active')->json('data.id');
        $this->postJson("/api/v1/hr/sick-sheets/{$hr}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(2, app(CompanyContext::class)->run($company, fn () => EmployeeTimetable::where('source', 'excuse')->count()), 'cancelled days leave the calendar');

        app(CompanyContext::class)->run($company, fn () => WorkflowTemplate::create(['process' => 'excuse-of-duty', 'is_active' => true])->steps()->create(['step_order' => 1, 'name' => 'HR', 'approver_type' => 'user', 'user_id' => $admin->id]));
        $pending = $this->actingAs($user)->post('/api/v1/self/sick-sheets', ['start_date' => '2026-11-23', 'end_date' => '2026-11-23', 'file' => $sheet()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.waiting_for', 'HR')->assertJsonPath('data.can_cancel', true)->json('data.id');
        $this->postJson("/api/v1/self/sick-sheets/{$pending}/withdraw")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->getJson('/api/v1/hr/sick-sheets')->assertForbidden();

        $this->actingAs($admin)->getJson('/api/v1/hr/settings/general')->assertJsonPath('data.hospital_patients', false);
        $this->getJson('/api/v1/hr/contacts')->assertJsonPath('meta.patient_ids', false);
        $this->putJson('/api/v1/hr/settings/general', ['hospital_patients' => true])->assertOk()->assertJsonPath('data.hospital_patients', true);
        $this->getJson('/api/v1/hr/contacts')->assertJsonPath('meta.patient_ids', true);
    }
}
