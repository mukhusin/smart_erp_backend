<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class HrmsImportTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company] = $this->createCompany('Kairuki', ['modules' => [['slug' => 'hr']]]);
        config(['database.connections.hrms' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('hrms');
        $this->hrms();
    }

    private function hrms(): void
    {
        $s = Schema::connection('hrms');
        $t = fn (string $name, \Closure $cols) => $s->create($name, function (Blueprint $b) use ($cols) {
            $b->id();
            $cols($b);
            $b->timestamps();
        });
        $t('banks', fn ($b) => [$b->string('name'), $b->string('code')->nullable(), $b->string('description')->nullable(), $b->boolean('isActive')]);
        $t('company_branches', fn ($b) => [$b->string('name'), $b->string('description')->nullable()]);
        $t('salary_scales', fn ($b) => [$b->string('name'), $b->decimal('minimum')->nullable(), $b->decimal('maximum')->nullable(), $b->string('description')->nullable(), $b->softDeletes()]);
        $t('nature_of_contracts', fn ($b) => [$b->string('contract_name'), $b->string('employeement_type'), $b->string('code'), $b->boolean('hasPayroll'), $b->boolean('hasAnnualLeave'),
            $b->text('contract_description')->nullable(), $b->text('template_content')->nullable(), $b->boolean('isActive'), $b->boolean('hasAllowance'), $b->boolean('hasOvertime'), $b->boolean('hasEmergencyFund'), $b->softDeletes()]);
        $t('proffesional_licences', fn ($b) => [$b->string('name'), $b->string('provider_name')->nullable(), $b->string('short_name')->nullable()]);
        $t('departments', fn ($b) => [$b->integer('company_branche_id')->nullable(), $b->string('name'), $b->string('short_name')->nullable(), $b->integer('parent')->nullable(),
            $b->integer('sub_parent')->nullable(), $b->string('description')->nullable(), $b->softDeletes()]);
        $t('job_positions', fn ($b) => [$b->integer('department_id')->nullable(), $b->string('title'), $b->string('description')->nullable(), $b->softDeletes()]);
        $t('users', fn ($b) => [$b->string('username'), $b->string('role_as')->nullable(), $b->string('email'), $b->timestamp('email_verified_at')->nullable(),
            $b->string('password'), $b->boolean('isActive'), $b->softDeletes()]);
        $t('employees', fn ($b) => [$b->string('fname'), $b->string('mname')->nullable(), $b->string('lname'), $b->string('profile_image')->nullable(), $b->string('gender'),
            $b->date('date_of_birth')->nullable(), $b->string('phone_number')->nullable(), $b->string('place_of_birth')->nullable(), $b->string('home_address')->nullable(),
            $b->string('email')->nullable(), $b->string('tin')->nullable(), $b->string('nationality')->nullable(), $b->string('id_number')->nullable(), $b->string('id_type')->nullable(),
            $b->string('marital_status')->nullable(), $b->string('emp_title')->nullable(), $b->integer('company_branche_id'), $b->integer('department_id'), $b->integer('section_id')->nullable(),
            $b->integer('job_position_id'), $b->date('employment_date')->nullable(), $b->string('emp_type'), $b->string('emp_category')->nullable(), $b->string('place_of_recruitment')->nullable(),
            $b->string('st_no'), $b->date('termination_date')->nullable(), $b->string('nssf_no')->nullable(), $b->string('nhif_no')->nullable(), $b->string('wcf_no')->nullable(),
            $b->string('employment_status'), $b->integer('user_id')->nullable(), $b->softDeletes()]);
        $t('employee_settings', fn ($b) => [$b->integer('employee_id'), $b->boolean('hasAnnualLeave'), $b->boolean('hasAttendance')]);
        $t('employee_contracts', fn ($b) => [$b->integer('employee_id'), $b->string('st_no')->nullable(), $b->integer('nature_of_contract_id'), $b->integer('salary_scale_id')->nullable(),
            $b->decimal('basic_salary', 12, 2), $b->integer('period')->nullable(), $b->date('start_date'), $b->date('end_date')->nullable(), $b->text('comment')->nullable(),
            $b->integer('created_by')->nullable(), $b->integer('updated_by')->nullable(), $b->boolean('isActive'), $b->string('status'), $b->date('date_of_change')->nullable(), $b->softDeletes()]);
        $t('emp_contract_comments', fn ($b) => [$b->integer('emp_contract_id'), $b->integer('employee_id')->nullable(), $b->text('comment'), $b->string('status')->nullable(), $b->softDeletes()]);
        $t('roles', fn ($b) => [$b->string('name'), $b->string('slug')]);
        $t('permissions', fn ($b) => [$b->string('name'), $b->string('slug')]);
        $t('roles_permissions', fn ($b) => [$b->integer('role_id'), $b->integer('permission_id')]);
        $t('users_roles', fn ($b) => [$b->integer('user_id'), $b->integer('role_id')]);
        $t('users_permissions', fn ($b) => [$b->integer('user_id'), $b->integer('permission_id')]);

        $db = DB::connection('hrms');
        $db->table('company_branches')->insert(['id' => 1, 'name' => 'Kairuki Hospital HQ']);
        $db->table('nature_of_contracts')->insert(['id' => 4, 'contract_name' => 'Specified time', 'employeement_type' => 'FULL TIME', 'code' => 'ST', 'hasPayroll' => 1,
            'hasAnnualLeave' => 1, 'isActive' => 1, 'hasAllowance' => 1, 'hasOvertime' => 1, 'hasEmergencyFund' => 0]);
        $db->table('departments')->insert([['id' => 7, 'company_branche_id' => 1, 'name' => 'Nursing', 'parent' => null], ['id' => 9, 'company_branche_id' => 1, 'name' => 'Ward 3', 'parent' => 7]]);
        $db->table('job_positions')->insert(['id' => 30, 'department_id' => 7, 'title' => 'Nurse']);
        $db->table('users')->insert([
            ['id' => 50, 'username' => 'asha@kh.test', 'email' => 'Asha@kh.test', 'password' => 'hash-asha', 'isActive' => 1, 'role_as' => 'normal_employee'],
            ['id' => 51, 'username' => 'juma@kh.test', 'email' => 'juma@kh.test', 'password' => 'hash-juma', 'isActive' => 1, 'role_as' => 'payroll'],
        ]);
        $employee = ['mname' => null, 'gender' => 'Female', 'company_branche_id' => 1, 'department_id' => 7, 'job_position_id' => 30, 'emp_category' => 'Primary', 'id_type' => 'NATIONAL ID', 'marital_status' => 'WIDOWER'];
        $db->table('employees')->insert([
            $employee + ['id' => 1001, 'fname' => 'Asha', 'lname' => 'Ali', 'section_id' => 9, 'emp_type' => 'FULL TIME', 'st_no' => 'ST1001', 'employment_status' => 'on probation', 'user_id' => 50, 'employment_date' => '2024-01-01'],
            $employee + ['id' => 1002, 'fname' => 'Juma', 'lname' => 'Omari', 'section_id' => null, 'emp_type' => 'PART TIME', 'st_no' => 'PT20', 'employment_status' => 'confirmed', 'user_id' => 51, 'employment_date' => '2020-01-01'],
        ]);
        $db->table('employee_settings')->insert(['employee_id' => 1001, 'hasAnnualLeave' => 1, 'hasAttendance' => 1]);
        $db->table('employee_contracts')->insert([
            ['id' => 1, 'employee_id' => 1001, 'st_no' => 'ST1001', 'nature_of_contract_id' => 4, 'basic_salary' => 900000, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'isActive' => 1, 'status' => 'active', 'created_by' => 1002],
            ['id' => 2, 'employee_id' => 1002, 'st_no' => 'PT20', 'nature_of_contract_id' => 4, 'basic_salary' => 300000, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'isActive' => 0, 'status' => ' 	resigned_30_days_notes', 'created_by' => null],
        ]);
        $db->table('emp_contract_comments')->insert(['emp_contract_id' => 1, 'employee_id' => 1002, 'comment' => 'Good work', 'status' => 'active']);
        $db->table('roles')->insert([['id' => 6, 'name' => 'Payroll Officer', 'slug' => 'payroll_officer'], ['id' => 14, 'name' => 'Normal employee', 'slug' => 'normal_employee']]);
        $db->table('permissions')->insert(['id' => 1, 'name' => 'View payroll', 'slug' => 'view-payroll']);
        $db->table('roles_permissions')->insert(['role_id' => 6, 'permission_id' => 1]);
        $db->table('users_roles')->insert([['user_id' => 51, 'role_id' => 6], ['user_id' => 50, 'role_id' => 14]]);
    }

    public function test_imports_and_runs_again_without_duplicates(): void
    {
        $steps = 'reference,organisation,users,employees,contracts,roles';
        $this->artisan('erp:import-hrms', ['company' => $this->company->slug, '--only' => $steps])->assertSuccessful();

        $asha = Employee::withoutCompanyScope()->where('employee_no', 'ST1001')->first();
        $this->assertSame('probation', $asha->employment_status);
        $this->assertSame('1001', $asha->attendance_code);
        $this->assertTrue((bool) $asha->has_attendance);
        $this->assertSame('nida', $asha->id_type);
        $this->assertSame('widowed', $asha->marital_status);
        $this->assertSame('Ward 3', DB::table('departments')->where('id', $asha->section_id)->value('name'));
        $this->assertSame((int) $asha->contract_id, (int) DB::table('employee_contracts')->where('employee_id', $asha->id)->value('id'));
        $this->assertSame('asha@kh.test', $asha->user->email);
        $this->assertSame('hash-asha', $asha->user->password);

        $juma = Employee::withoutCompanyScope()->where('employee_no', 'PT20')->first();
        $this->assertSame('terminated', $juma->employment_status);
        $this->assertSame('part_time', $juma->emp_type);
        $this->assertSame('resigned_30_days_notes', DB::table('employee_contracts')->where('employee_id', $juma->id)->value('status'));
        $this->assertSame($juma->user_id, DB::table('employee_contract_events')->value('user_id'));
        $this->assertSame($juma->user_id, DB::table('employee_contracts')->where('employee_id', $asha->id)->value('created_by'));

        setPermissionsTeamId($this->company->id);
        $this->assertTrue($juma->user->fresh()->hasRole('Payroll Officer'));
        $this->assertTrue($juma->user->fresh()->hasPermissionTo('payroll.view'));
        $this->assertTrue($asha->user->fresh()->hasRole('employee'));

        User::whereKey($asha->user_id)->update(['password' => 'changed-in-erp']);
        DB::connection('hrms')->table('employees')->where('id', 1001)->update(['phone_number' => '0712000000']);
        $counts = [Employee::withoutCompanyScope()->count(), User::count(), DB::table('employee_contracts')->count(), DB::table('departments')->count()];

        $this->artisan('erp:import-hrms', ['company' => $this->company->slug, '--only' => $steps])->assertSuccessful();

        $this->assertSame($counts, [Employee::withoutCompanyScope()->count(), User::count(), DB::table('employee_contracts')->count(), DB::table('departments')->count()]);
        $this->assertSame('0712000000', $asha->fresh()->phone_number);
        $this->assertSame('changed-in-erp', User::find($asha->user_id)->password);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $before = Employee::withoutCompanyScope()->count();
        $this->artisan('erp:import-hrms', ['company' => $this->company->slug, '--only' => 'reference,organisation,users,employees', '--dry-run' => true])->assertSuccessful();
        $this->assertSame($before, Employee::withoutCompanyScope()->count());
        $this->assertSame(0, DB::table('legacy_ids')->count());
    }
}
