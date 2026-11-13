<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\JobPosition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Mail\StaffInviteMail;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class EmployeeSheetTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private string $department;

    private string $position;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr']]]);
        setPermissionsTeamId($this->company->id);
        $this->department = Department::withoutCompanyScope()->where('company_id', $this->company->id)->value('name');
        $this->position = JobPosition::withoutCompanyScope()->where('company_id', $this->company->id)->value('title');
    }

    private function csv(array $rows): UploadedFile
    {
        $lines = ['employee_no,fname,lname,gender,branch,department,position,emp_type,employment_date,employment_status,probation_start,probation_period,email,create_account'];
        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }

        return UploadedFile::fake()->createWithContent('staff.csv', implode("\n", $lines));
    }

    private function row(array $o = []): array
    {
        return array_replace(['', 'Asha', 'Mussa', 'female', 'head office', $this->department, $this->position, 'Full time', '01/11/2026', 'probation', '2026-11-01', '6', 'asha@alpha.test', 'yes'], $o);
    }

    public function test_checks_rows_then_creates_all_or_nothing(): void
    {
        Mail::fake();
        $bad = $this->row([1 => 'Juma', 4 => 'Nowhere', 6 => 'Pilot', 8 => '31/02/2026', 12 => 'asha@alpha.test']);

        $this->actingAs($this->admin)->post('/api/v1/hr/employees/import', ['file' => $this->csv([$this->row(), $bad]), 'commit' => 1], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('invalid', 1)->assertJsonPath('created', 0)
            ->assertJsonPath('rows.1.line', 3);
        $this->assertSame(1, Employee::count());

        $check = $this->actingAs($this->admin)->post('/api/v1/hr/employees/import', ['file' => $this->csv([$this->row()])], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('valid', 1)->assertJsonPath('created', 0);
        $this->assertSame([], $check->json('rows.0.errors'));

        $this->actingAs($this->admin)->post('/api/v1/hr/employees/import', ['file' => $this->csv([$this->row(), $this->row([0 => 'EMP-0100', 1 => 'Juma', 12 => '', 13 => 'no'])]), 'commit' => 1], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('created', 2)->assertJsonPath('invites_sent', 1);

        $asha = Employee::where('fname', 'Asha')->first();
        $this->assertSame('Female', $asha->gender);
        $this->assertSame('full_time', $asha->emp_type);
        $this->assertSame('2026-11-01', $asha->employment_date->toDateString());
        $this->assertNotNull($asha->user_id);
        $this->assertNotEmpty($asha->employee_no);
        $this->assertTrue(Employee::where('employee_no', 'EMP-0100')->whereNull('user_id')->exists());
        Mail::assertSent(StaffInviteMail::class, 1);
    }

    public function test_rejects_missing_columns_and_duplicates_in_file(): void
    {
        $file = UploadedFile::fake()->createWithContent('x.csv', "fname,lname\nA,B");
        $this->actingAs($this->admin)->post('/api/v1/hr/employees/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'gender'));

        $res = $this->actingAs($this->admin)->post('/api/v1/hr/employees/import', ['file' => $this->csv([$this->row([0 => 'X1']), $this->row([0 => 'X1', 12 => 'b@alpha.test'])])], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('invalid', 1);
        $this->assertStringContainsString('line 2', $res->json('rows.1.errors.0'));
    }

    public function test_exports_chosen_columns_and_hides_salary(): void
    {
        $csv = $this->actingAs($this->admin)->get('/api/v1/hr/employees/export?columns=employee_no,name,department,basic_salary,unknown')
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        $lines = array_map('str_getcsv', explode("\n", trim(substr($csv, 3))));
        $this->assertSame(['Employee number', 'Full name', 'Department', 'Basic salary'], $lines[0]);
        $this->assertSame('Admin Alpha', $lines[1][1]);

        $clerk = User::factory()->create(['company_id' => $this->company->id]);
        $clerk->givePermissionTo('hr.employees.view');
        $this->actingAs($clerk)->getJson('/api/v1/hr/employees/export-columns')->assertOk()
            ->assertJsonMissing(['value' => 'basic_salary']);
        $this->assertStringNotContainsString('Basic salary', $this->actingAs($clerk)->get('/api/v1/hr/employees/export?columns=name,basic_salary')->getContent());
        $this->actingAs($clerk)->get('/api/v1/hr/employees/import-template')->assertForbidden();
        $this->actingAs($this->admin)->get('/api/v1/hr/employees/import-template')->assertOk();
    }
}
