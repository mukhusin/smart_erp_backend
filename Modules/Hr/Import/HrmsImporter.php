<?php

namespace Modules\Hr\Import;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Support\DefaultRoles;
use Modules\Hr\Support\EmployeeFiles;
use Modules\Hr\Support\EmployeeOptions as O;
use Modules\Hr\Support\LeaveBalances;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Copies HRMS data into one ERP company. Every row is matched through `legacy_ids`, so a run inserts new rows and
 * updates the ones copied before; ERP rows that did not come from HRMS are never touched.
 */
class HrmsImporter
{
    public const STEPS = ['reference', 'organisation', 'users', 'employees', 'contracts', 'profiles', 'statutory', 'structure', 'leave', 'pay', 'payroll', 'transactions', 'roster', 'attendance', 'archive', 'roles', 'files'];

    private const ROLE_MAP = [
        'super_admin' => DefaultRoles::COMPANY_ADMIN,
        'director_general' => 'director-general',
        'ddg' => 'director',
        'director' => 'director',
        'director_clinical_&_medical_services' => 'director',
        'director_ivf' => 'director',
        'hr_manager' => 'hr-manager',
        'human_resources_manager' => 'hr-manager',
        'hr_officer' => 'hr-officer',
        'hod' => 'hod',
        'assistant_hod' => 'hod',
        'hos' => 'section-head',
        'normal_employee' => 'employee',
    ];

    /** HRMS leave type code => ERP default leave type code (HRMS "SL" is study leave, ERP "SL" is sick leave). */
    private const LEAVE_TYPE_MATCH = ['AL' => 'AL', 'CLW' => 'CL', 'LWP' => 'UL', 'MLS' => 'ML', 'PLS' => 'PL', 'SLF' => 'SL'];

    private const LEAVE_TYPE_CODE = ['SL' => 'STL'];

    private const LEAVE_STATUS = ['returned' => 'reported', 'reported' => 'reported', 'ended' => 'reported', 'on leave' => 'on_leave',
        'approved' => 'approved', 'pending' => 'pending', 'rejected' => 'rejected'];

    public const OPENING_BALANCE = 'Opening balance from HRMS';

    private const CONTRACT_STATUS = ['contract end' => 'ended', 'vacation_of_office' => 'abscondment', 'expired' => 'ended', 'pending' => 'ended'];

    /** @var array<string, array{read: int, inserted: int, updated: int, skipped: int, notes: list<string>}> */
    private array $report = [];

    private ConnectionInterface $src;

    private int $companyId;

    /** @var array<string, list<string>> */
    private array $required = [];

    /** @var array<int, int|null> HRMS employee id => HRMS user id */
    private array $employeeUsers = [];

    public function __construct(private readonly Company $company, private readonly LegacyIds $ids, private readonly ?string $filesPath = null, private readonly bool $dryRun = false)
    {
        $this->src = DB::connection('hrms');
        $this->companyId = $company->id;
    }

    /** @param list<string> $steps @return array<string, array{read: int, inserted: int, updated: int, skipped: int, notes: list<string>}> */
    public function run(array $steps): array
    {
        setPermissionsTeamId($this->companyId);
        $this->employeeUsers = $this->src->table('employees')->pluck('user_id', 'id')->all();

        foreach (self::STEPS as $step) {
            if (in_array($step, $steps, true)) {
                $this->{'step'.ucfirst($step)}();
            }
        }

        return $this->report;
    }

    private function stepReference(): void
    {
        $this->sync('banks', 'banks', fn ($r) => [
            'company_id' => $this->companyId, 'name' => trim($r->name), 'code' => $r->code, 'description' => $r->description, 'is_active' => (bool) $r->isActive,
        ], match: fn ($r) => $this->findBy('banks', ['name' => trim($r->name)]));

        $this->sync('company_branches', 'company_branches', fn ($r) => [
            'company_id' => $this->companyId, 'name' => trim($r->name), 'is_active' => true,
        ], match: fn ($r) => $this->findBy('company_branches', ['name' => trim($r->name)]), keepOnUpdate: ['is_active']);

        $this->sync('salary_scales', 'salary_scales', fn ($r) => [
            'company_id' => $this->companyId, 'name' => $r->name, 'minimum' => $r->minimum, 'maximum' => $r->maximum, 'description' => $r->description, 'deleted_at' => $r->deleted_at,
        ]);

        $this->sync('nature_of_contracts', 'nature_of_contracts', fn ($r) => [
            'company_id' => $this->companyId, 'contract_name' => $r->contract_name, 'code' => $r->code,
            'employment_type' => O::empTypeOf($r->employeement_type) ?? $r->employeement_type,
            'has_payroll' => (bool) $r->hasPayroll, 'has_annual_leave' => (bool) $r->hasAnnualLeave, 'has_allowance' => (bool) $r->hasAllowance,
            'has_overtime' => (bool) $r->hasOvertime, 'has_emergency_fund' => (bool) $r->hasEmergencyFund, 'is_active' => (bool) $r->isActive,
            'contract_description' => $r->contract_description, 'template_content' => $r->template_content, 'deleted_at' => $r->deleted_at,
        ], match: fn ($r) => $this->findBy('nature_of_contracts', ['code' => $r->code]));

        $this->sync('proffesional_licences', 'professional_licences', fn ($r) => [
            'company_id' => $this->companyId, 'name' => $r->name, 'provider_name' => $r->provider_name, 'short_name' => $r->short_name, 'is_active' => true,
        ], match: fn ($r) => $this->findBy('professional_licences', ['name' => $r->name]));
    }

    private function stepOrganisation(): void
    {
        $this->sync('departments', 'departments', fn ($r) => [
            'company_id' => $this->companyId, 'company_branch_id' => $this->ids->get('company_branches', $r->company_branche_id),
            'parent_id' => $this->ids->get('departments', $r->sub_parent ?: $r->parent), 'name' => trim($r->name), 'short_name' => $r->short_name,
            'description' => $r->description, 'is_active' => true, 'deleted_at' => $r->deleted_at,
        ], query: fn ($q) => $q->orderByRaw('parent is not null')->orderBy('id'),
            match: fn ($r) => $this->findBy('departments', ['name' => trim($r->name)]), keepOnUpdate: ['is_active', 'work_schedule_id']);

        $this->sync('job_positions', 'job_positions', fn ($r) => [
            'company_id' => $this->companyId, 'department_id' => $this->ids->get('departments', $r->department_id), 'title' => trim($r->title),
            'description' => $r->description, 'is_active' => true, 'deleted_at' => $r->deleted_at,
        ], match: fn ($r) => $this->findBy('job_positions', ['title' => trim($r->title), 'department_id' => $this->ids->get('departments', $r->department_id)]), keepOnUpdate: ['is_active']);
    }

    private function stepUsers(): void
    {
        $branches = $this->src->table('employees')->pluck('company_branche_id', 'user_id')->all();

        $this->sync('users', 'users', function ($r) use ($branches) {
            $email = strtolower(trim((string) $r->email));
            $elsewhere = fn (string $column, string $value) => DB::table('users')->where($column, $value)
                ->where(fn ($q) => $q->where('company_id', '!=', $this->companyId)->orWhereNull('company_id'))->exists();
            if ($elsewhere('email', $email)) {
                return "user {$r->username}: email {$email} belongs to another company";
            }
            $username = $r->username;
            if ($elsewhere('username', $username)) {
                $username = $email;
                $this->note('users', "user {$r->username}: username taken by another company, username set to {$email}");
            }

            return [
                'company_id' => $this->companyId, 'company_branch_id' => $this->ids->get('company_branches', $branches[$r->id] ?? null),
                'username' => $username, 'email' => $email, 'role_as' => $r->role_as, 'email_verified_at' => $r->email_verified_at ?? $r->created_at ?? now(),
                'password' => $r->password, 'is_active' => (bool) $r->isActive, 'is_platform_admin' => false, 'deleted_at' => $r->deleted_at,
            ];
        }, match: fn ($r) => DB::table('users')->where('company_id', $this->companyId)
            ->where(fn ($q) => $q->where('email', strtolower(trim((string) $r->email)))->orWhere('username', $r->username))->value('id'),
            keepOnUpdate: ['password', 'remember_token', 'avatar']);
    }

    private function stepEmployees(): void
    {
        $settings = $this->src->table('employee_settings')->get()->keyBy('employee_id');
        $hasProbation = $this->src->getSchemaBuilder()->hasColumn('employees', 'probation_start');

        $this->sync('employees', 'employees', function ($r) use ($settings, $hasProbation) {
            $setting = $settings[$r->id] ?? null;
            $data = [
                'company_id' => $this->companyId, 'company_branch_id' => $this->ids->get('company_branches', $r->company_branche_id),
                'department_id' => $this->ids->get('departments', $r->department_id), 'section_id' => $this->ids->get('departments', $r->section_id),
                'job_position_id' => $this->ids->get('job_positions', $r->job_position_id), 'user_id' => $this->ids->get('users', $r->user_id),
                'employee_no' => trim($r->st_no), 'attendance_code' => (string) $r->id,
                'fname' => trim($r->fname), 'mname' => $this->blank($r->mname), 'lname' => trim($r->lname), 'gender' => $r->gender,
                'date_of_birth' => $this->date($r->date_of_birth), 'phone_number' => $this->blank($r->phone_number), 'place_of_birth' => $this->blank($r->place_of_birth),
                'home_address' => $this->blank($r->home_address), 'email' => $this->blank(strtolower((string) $r->email)), 'tin' => $this->blank($r->tin),
                'nationality' => $this->blank($r->nationality), 'id_number' => $this->blank($r->id_number), 'id_type' => $this->idType($r->id_type),
                'marital_status' => $this->marital($r->marital_status), 'emp_title' => $this->blank($r->emp_title), 'employment_date' => $this->date($r->employment_date),
                'place_of_recruitment' => $this->blank($r->place_of_recruitment), 'emp_type' => O::empTypeOf($r->emp_type) ?? 'full_time',
                'emp_category' => $r->emp_category ?: 'Primary', 'nssf_no' => $this->blank($r->nssf_no), 'nhif_no' => $this->blank($r->nhif_no),
                'wcf_no' => $this->blank($r->wcf_no), 'employment_status' => in_array(strtolower((string) $r->employment_status), ['on probation', 'probation'], true) ? 'probation' : 'confirmed',
                'termination_date' => $this->date($r->termination_date),
                'has_annual_leave' => $setting ? (bool) $setting->hasAnnualLeave : true, 'has_attendance' => $setting ? (bool) $setting->hasAttendance : false,
                'deleted_at' => $r->deleted_at,
            ];
            if ($hasProbation) {
                $data += ['probation_start' => $this->date($r->probation_start), 'probation_period' => $r->probation_period, 'probation_end' => $this->date($r->probation_end)];
            }

            return $data;
        }, match: fn ($r) => $this->findBy('employees', ['employee_no' => trim($r->st_no)]), keepOnUpdate: ['profile_image', 'contract_id', 'contract_status']);

        foreach ($this->src->table('employees')->get(['id', 'company_branche_id', 'user_id']) as $e) {
            if ($userId = $this->ids->get('users', $e->user_id)) {
                DB::table('users')->where('id', $userId)->update(['company_branch_id' => $this->ids->get('company_branches', $e->company_branche_id)]);
            }
        }
    }

    private function stepContracts(): void
    {
        $leaving = array_diff(array_keys(O::CONTRACT_END_REASONS), O::CONTRACT_KEEPS_EMPLOYEE);

        $this->sync('employee_contracts', 'employee_contracts', function ($r) {
            $employee = $this->ids->get('employees', $r->employee_id);
            if (! $employee) {
                return "contract {$r->id}: employee {$r->employee_id} not imported";
            }

            return [
                'employee_id' => $employee, 'nature_of_contract_id' => $this->ids->get('nature_of_contracts', $r->nature_of_contract_id),
                'salary_scale_id' => $this->ids->get('salary_scales', $r->salary_scale_id), 'contract_no' => $r->st_no, 'basic_salary' => $r->basic_salary ?? 0,
                'period' => $r->period, 'start_date' => $this->date($r->start_date), 'end_date' => $this->date($r->end_date),
                'date_of_change' => $this->date($r->date_of_change), 'comment' => $r->comment, 'is_active' => (bool) $r->isActive,
                'status' => $this->contractStatus($r->status), 'created_by' => $this->userOfEmployee($r->created_by),
                'updated_by' => $this->userOfEmployee($r->updated_by), 'deleted_at' => $r->deleted_at,
            ];
        });

        $this->sync('emp_contract_comments', 'employee_contract_events', function ($r) {
            $contract = $this->ids->get('employee_contracts', $r->emp_contract_id);
            if (! $contract || $r->deleted_at) {
                return "comment {$r->id}: contract missing or comment deleted";
            }

            return ['employee_contract_id' => $contract, 'user_id' => $this->userOfEmployee($r->employee_id), 'action' => 'commented', 'status' => $r->status, 'comment' => $r->comment];
        });

        $employees = array_values($this->ids->all('employees'));
        foreach (array_chunk($employees, 500) as $chunk) {
            $contracts = DB::table('employee_contracts')->whereIn('employee_id', $chunk)->whereNull('deleted_at')
                ->orderByDesc('is_active')->orderByDesc('start_date')->orderByDesc('id')->get()->groupBy('employee_id');
            foreach ($chunk as $employeeId) {
                $list = $contracts->get($employeeId, collect());
                $active = $list->firstWhere('is_active', 1);
                $latest = $list->first();
                $open = $list->contains(fn ($c) => in_array($c->status, ['active', 'waiting'], true));
                $left = ! $open && $latest && in_array($latest->status, $leaving, true);
                $update = ['contract_id' => $active?->id, 'contract_status' => $active?->status ?? ''];
                if ($left) {
                    $update += ['employment_status' => 'terminated', 'termination_date' => $latest->date_of_change ?? $latest->end_date];
                }
                DB::table('employees')->where('id', $employeeId)->update($update);
            }
        }
    }

    private function stepProfiles(): void
    {
        $employee = fn ($r) => $this->ids->get('employees', $r->employee_id);
        $missing = fn ($r) => "employee {$r->employee_id} not imported";

        $this->sync('employee_bank_accounts', 'employee_bank_accounts', fn ($r) => $employee($r) ? [
            'employee_id' => $employee($r), 'bank_id' => $this->ids->get('banks', $r->bank_id), 'account_name' => $r->name, 'branch' => $r->branch,
            'account_number' => $r->account, 'is_active' => (bool) $r->isActive,
        ] : $missing($r));

        $this->sync('employee_dependants', 'employee_dependants', fn ($r) => $employee($r) ? [
            'employee_id' => $employee($r), 'fullname' => $r->fullname, 'gender' => $r->gender, 'address' => $r->address, 'date_of_birth' => $this->date($r->date_of_birth),
            'phone' => $r->phone, 'email' => $r->email, 'relationship' => ['father' => 'father', 'mother' => 'mother', 'child' => 'child', 'sister' => 'spouse', 'spouse' => 'spouse'][strtolower((string) $r->relationship)] ?? 'child',
            'is_next_of_kin' => (bool) $r->isNextOfKin,
        ] : $missing($r), keepOnUpdate: ['profile_image', 'birth_certificate', 'birth_certificate_name']);

        $docTypes = ['birth certificate' => 'birth_certificate', 'registration certificate' => 'registration_certificate', 'tin certificate' => 'tin_certificate',
            'marriage certificate' => 'marriage_certificate', 'academic certificate' => 'academic_certificate', 'proffesional cerificate' => 'professional_certificate'];
        $this->sync('employee_documents', 'employee_documents', fn ($r) => $employee($r) ? [
            'employee_id' => $employee($r), 'file_name' => $r->file_name, 'file_path' => "hrms/employee/document/{$r->file_name}", 'file_type' => $docTypes[strtolower(trim((string) $r->file_type))] ?? 'other', 'description' => $r->description,
        ] : $missing($r), keepOnUpdate: ['file_path', 'size', 'mime_type']);

        $levels = [1 => 'o_level', 2 => 'a_level', 3 => 'certificate', 4 => 'ordinary_diploma', 5 => 'advanced_diploma', 6 => 'degree', 7 => 'masters', 8 => 'phd', 9 => 'professor'];
        $this->sync('employee_qualifications', 'employee_qualifications', fn ($r) => $employee($r) ? [
            'employee_id' => $employee($r), 'education_level' => $levels[(int) $r->education_level] ?? 'certificate', 'program' => $r->program,
            'date_of_start' => $this->date($r->date_of_start), 'date_of_end' => $this->date($r->date_of_end), 'end_year' => $r->end_year,
        ] : $missing($r), keepOnUpdate: ['certificate', 'certificate_name', 'institution']);

        $this->sync('employee_experiances', 'employee_experiences', fn ($r) => $employee($r) ? [
            'employee_id' => $employee($r), 'company_name' => $r->company_name, 'position' => $r->position, 'start_date' => $this->date($r->start_date),
            'end_date' => $this->date($r->end_date), 'salary' => $r->salary, 'description' => $r->description,
        ] : $missing($r));

        $this->sync('employee_licences', 'employee_licences', fn ($r) => $employee($r) ? [
            'employee_id' => $employee($r), 'professional_licence_id' => $this->ids->get('proffesional_licences', $r->proffesional_licence_id),
            'start_date' => $this->date($r->start_date), 'end_date' => $this->date($r->end_date), 'file_name' => $r->file_name,
        ] : $missing($r), keepOnUpdate: ['file_path', 'licence_no']);
    }

    private function stepStatutory(): void
    {
        foreach (['employee_funds' => ['amount'], 'nhifs' => ['contribution_no', 'emp_rate', 'employer_rate'], 'nssfs' => ['contribution_no', 'emp_rate', 'employer_rate'],
            'heslbs' => ['contribution_no', 'emp_rate'], 'tughes' => ['contribution_no', 'emp_rate'], 'nics' => ['contribution_no', 'emp_rate']] as $table => $columns) {
            $this->sync($table, $table, function ($r) use ($columns) {
                $employee = $this->ids->get('employees', $r->employee_id);
                if (! $employee) {
                    return "employee {$r->employee_id} not imported";
                }
                $data = ['employee_id' => $employee, 'is_active' => (bool) $r->isActive];
                foreach ($columns as $c) {
                    $data[$c] = $r->{$c};
                }

                return $data;
            });
        }
    }

    private function stepStructure(): void
    {
        $this->sync('emp_supervisors', 'emp_supervisors', function ($r) {
            $employee = $this->ids->get('employees', $r->employee_id);
            $supervisor = $this->ids->get('employees', $r->supervisor_id);

            return $employee && $supervisor ? [
                'employee_id' => $employee, 'supervisor_id' => $supervisor, 'from_date' => $this->date($r->from_date), 'to_date' => $this->date($r->to_date),
                'status' => $r->status === 'active' ? 'active' : 'inactive',
            ] : "supervisor link {$r->id}: employee missing";
        });

        $this->sync('head_of_deaprtments', 'head_of_departments', function ($r) {
            $employee = $this->ids->get('employees', $r->employee_id);
            $department = $this->ids->get('departments', $r->department_id);

            return $employee && $department ? [
                'employee_id' => $employee, 'department_id' => $department, 'level' => $r->level === 'hos' ? 'hos' : 'hod',
                'started_at' => $this->date($r->created_at), 'ended_at' => $r->isActive ? null : $this->date($r->updated_at),
                'change_reason' => $r->r_reason ?: $r->c_reason, 'is_active' => (bool) $r->isActive,
            ] : "head {$r->id}: employee or department missing";
        });

        foreach (['internship_periods' => ['internship', 'year_of_intern'], 'resident_periods' => ['residency', 'year_of_resident']] as $table => [$type, $yearColumn]) {
            $this->sync($table, 'employee_placements', function ($r) use ($type, $yearColumn) {
                $employee = $this->ids->get('employees', $r->employee_id);
                if (! $employee) {
                    return "employee {$r->employee_id} not imported";
                }
                $start = $this->date($r->start_date);
                $status = $r->status === 'active' ? ($start && $start > now()->toDateString() ? 'upcoming' : 'active') : 'ended';

                return ['employee_id' => $employee, 'type' => $type, 'year' => $r->{$yearColumn}, 'period' => $r->period, 'start_date' => $start,
                    'end_date' => $this->date($r->end_date), 'status' => $status, 'comment' => $r->comment];
            });
        }

        $patients = $this->src->table('employee_patient_ids')->count();
        if ($patients) {
            $this->stats('employee_patient_ids')['skipped'] = $patients;
            $this->note('employee_patient_ids', 'no ERP table yet (gap E-14), not copied');
        }
    }

    private function stepLeave(): void
    {
        $this->sync('leave_types', 'leave_types', function ($r) {
            $name = strtolower($r->name);
            $kind = match (true) {
                str_contains($name, 'annual') => 'annual', str_contains($name, 'maternity') => 'maternity', str_contains($name, 'paternity') => 'paternity',
                str_contains($name, 'compassionate') => 'compassionate', str_contains($name, 'sick') => 'sick', str_contains($name, 'study') => 'study',
                str_contains($name, 'without pay') => 'unpaid', default => 'other',
            };
            $gender = strtolower((string) $r->gender);

            return [
                'company_id' => $this->companyId, 'name' => $r->name, 'code' => self::LEAVE_TYPE_CODE[$r->code] ?? $r->code, 'kind' => $kind,
                'gender' => in_array($gender, ['female', 'male'], true) ? $gender : null, 'with_pay' => (bool) $r->withPay, 'max_days' => $r->max_days,
                'day_count' => in_array($kind, ['annual', 'sick', 'maternity', 'study'], true) ? 'calendar' : 'working',
                'limit_per' => in_array($kind, ['annual', 'sick'], true) ? 'year' : 'request', 'is_active' => (bool) $r->isActive, 'deleted_at' => $r->deleted_at,
            ];
        }, match: fn ($r) => isset(self::LEAVE_TYPE_MATCH[$r->code]) ? $this->findBy('leave_types', ['code' => self::LEAVE_TYPE_MATCH[$r->code]]) : null,
            keepOnUpdate: ['name', 'code', 'kind', 'gender', 'day_count', 'limit_per', 'carry_over_days', 'notice_days', 'requires_document', 'employee_types', 'color']);

        $this->sync('absence_requests', 'absence_requests', fn ($r) => ($employee = $this->ids->get('employees', $r->employee_id)) ? [
            'company_id' => $this->companyId, 'employee_id' => $employee, 'last_updated_by' => $this->ids->get('employees', $r->last_updated_by),
            'from_date' => $this->date($r->from_date), 'to_date' => $this->date($r->to_date), 'from_time' => $r->from_time, 'to_time' => $r->to_time,
            'number_of_days' => $r->number_of_days, 'description' => $r->description, 'address' => $r->address, 'has_per_diem' => (bool) $r->has_per_diem,
            'status' => strtolower((string) $r->status),
        ] : "employee {$r->employee_id} not imported");

        $this->sync('emp_leaves', 'emp_leaves', function ($r) {
            $employee = $this->ids->get('employees', $r->employee_id);
            $type = $this->ids->get('leave_types', $r->leave_type_id);
            if (! $employee || ! $type) {
                return "leave {$r->id}: employee or leave type missing";
            }
            $details = array_filter(['deceased_name' => $this->blank($r->jina_la_marehem), 'relationship' => $this->blank($r->uhusiano_na_marehem), 'death_date' => $this->date($r->death_date)]);

            return [
                'company_id' => $this->companyId, 'employee_id' => $employee, 'leave_type_id' => $type,
                'hod_id' => $this->ids->get('employees', $r->hod_id), 'hr_id' => $this->ids->get('employees', $r->hr_id), 'department_id' => $this->ids->get('departments', $r->department_id),
                'start_date' => $this->date($r->start_date), 'end_date' => $this->date($r->end_date),
                'requested_start_date' => $this->date($r->requested_start_date ?: $r->start_date), 'requested_end_date' => $this->date($r->requested_end_date ?: $r->end_date),
                'days' => $r->days ?? 0, 'days_requested' => $r->days_requested ?: ($r->days ?? 0), 'leave_year' => $r->leave_year ?: substr((string) $r->start_date, 0, 4),
                'description' => $r->description, 'details' => $details ? json_encode($details) : null, 'hod_remark' => $r->hod_remark,
                'hod_is_approved' => (bool) $r->hod_isApproved, 'hr_office_remark' => $r->hr_office_remark, 'hr_office_is_approved' => (bool) $r->hr_office_isApproved,
                'report_date' => $this->date($r->report_date), 'is_reported' => (bool) $r->isReported, 'leave_phone' => $r->leave_phone, 'leave_address' => $r->leave_address,
                'days_passed' => $r->days_passed ?? 0, 'balance' => $r->balance ?? 0, 'deducted' => $r->deducted ?? 0, 'amount' => $r->amount ?? 0,
                'is_active' => (bool) $r->isActive, 'status' => self::LEAVE_STATUS[strtolower(trim((string) $r->status))] ?? 'reported', 'source' => 'employee',
                'requested_by' => $this->userOfEmployee($r->employee_id),
            ];
        });

        $this->sync('rosters', 'rosters', fn ($r) => [
            'company_id' => $this->companyId, 'type' => 'leave', 'title' => $r->title, 'department_id' => $this->ids->get('departments', $r->department_id),
            'section_id' => $this->ids->get('departments', $r->section_id), 'roster_year' => $r->roster_year, 'from_date' => $this->date($r->from_date),
            'to_date' => $this->date($r->to_date), 'status' => $r->status === 'active' ? 'published' : 'pending', 'is_active' => (bool) $r->isActive,
            'deleted_at' => $r->deleted_at,
        ], query: fn ($q) => $q->where('type', 'leave')->orderBy('id'));

        $annual = DB::table('leave_types')->where('company_id', $this->companyId)->where('kind', 'annual')->whereNull('deleted_at')->value('id');
        if (! $annual) {
            $this->note('annual_leave_rosters', 'no annual leave type in the ERP company, annual leave not copied');

            return;
        }

        $this->sync('annual_leave_rosters', 'emp_leaves', function ($r) use ($annual) {
            $status = self::LEAVE_STATUS[strtolower(trim((string) $r->status))] ?? null;
            $employee = $this->ids->get('employees', $r->employee_id);
            if (! $status || ! $employee) {
                return "annual leave {$r->id}: {$r->status}, kept as a plan only";
            }

            return [
                'company_id' => $this->companyId, 'employee_id' => $employee, 'leave_type_id' => $annual,
                'hod_id' => $this->ids->get('employees', $r->hod_id), 'hr_id' => $this->ids->get('employees', $r->hr_id), 'department_id' => $this->ids->get('departments', $r->department_id),
                'start_date' => $this->date($r->start_date), 'end_date' => $this->date($r->end_date),
                'requested_start_date' => $this->date($r->changed_start_date ?: $r->start_date), 'requested_end_date' => $this->date($r->changed_end_date ?: $r->end_date),
                'days' => $r->days ?? 0, 'days_requested' => $r->days ?? 0, 'leave_year' => $r->leave_year, 'description' => $r->description,
                'hod_remark' => $r->hod_remark, 'hod_is_approved' => (bool) $r->hod_isApproved, 'hr_office_remark' => $r->hr_office_remark,
                'hr_office_is_approved' => (bool) $r->hr_office_isApproved, 'report_date' => $this->date($r->report_date), 'is_reported' => (bool) $r->isReported,
                'leave_phone' => $r->leave_phone, 'leave_address' => $r->leave_address, 'days_passed' => $r->days_passed ?? 0, 'balance' => $r->actual_balance ?? 0,
                'is_active' => (bool) $r->isActive, 'status' => $status, 'source' => 'employee', 'requested_by' => $this->userOfEmployee($r->employee_id),
            ];
        }, key: 'annual_leave_rosters:leave');

        $this->sync('annual_leave_rosters', 'annual_leave_rosters', function ($r) {
            $employee = $this->ids->get('employees', $r->employee_id);
            if (! $employee) {
                return "employee {$r->employee_id} not imported";
            }
            $leave = $this->ids->get('annual_leave_rosters:leave', $r->id);
            $status = strtolower((string) $r->status);

            return [
                'company_id' => $this->companyId, 'roster_id' => $this->ids->get('rosters', $r->roster_id), 'employee_id' => $employee, 'emp_leave_id' => $leave,
                'hod_id' => $this->ids->get('employees', $r->hod_id), 'hr_id' => $this->ids->get('employees', $r->hr_id), 'department_id' => $this->ids->get('departments', $r->department_id),
                'start_date' => $this->date($r->start_date), 'end_date' => $this->date($r->end_date), 'days' => $r->days ?? 0, 'leave_year' => $r->leave_year,
                'report_date' => $this->date($r->report_date), 'is_reported' => (bool) $r->isReported, 'hod_remark' => $r->hod_remark, 'hod_is_approved' => (bool) $r->hod_isApproved,
                'hr_office_remark' => $r->hr_office_remark, 'hr_office_is_approved' => (bool) $r->hr_office_isApproved, 'description' => $r->description,
                'leave_phone' => $r->leave_phone, 'leave_address' => $r->leave_address, 'last_year_balance' => $r->last_year_balance ?? 0,
                'current_balance' => $r->current_balance ?? 0, 'actual_balance' => $r->actual_balance ?? 0,
                'status' => ! $leave ? 'planned' : ($status === 'pending' ? 'requested' : 'taken'), 'is_active' => (bool) $r->isActive,
            ];
        });

        $this->openingBalances($annual);
    }

    /** Sets each employee's annual leave available now to the HRMS balance of their latest annual leave entry this year. */
    private function openingBalances(int $annualTypeId): void
    {
        $stats = &$this->stats('leave_opening_balances');
        $type = LeaveType::find($annualTypeId);
        $balances = app(LeaveBalances::class);
        $year = (string) now()->year;
        $latest = $this->src->table('annual_leave_rosters')->where('leave_year', $year)->orderBy('id')->get()->keyBy('employee_id');

        foreach ($latest as $hrmsEmployee => $row) {
            $stats['read']++;
            $employee = Employee::find($this->ids->get('employees', $hrmsEmployee));
            if (! $employee || $employee->employment_status === 'terminated') {
                $stats['skipped']++;

                continue;
            }
            $balances->forget();
            $period = $balances->for($employee, $type);
            DB::table('leave_balance_adjustments')->where('employee_id', $employee->id)->where('leave_type_id', $type->id)
                ->where('leave_year', $period['year'])->where('reason', self::OPENING_BALANCE)->delete();
            $balances->forget();
            $current = $balances->for($employee, $type);
            $raw = $current['entitled'] + $current['carried_over'] + $current['adjustments'] - $current['taken'] - $current['pending'];
            $days = round((float) $row->actual_balance - $raw, 1);
            if ($current['eligible'] && abs($days) >= 0.5) {
                DB::table('leave_balance_adjustments')->insert(['company_id' => $this->companyId, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
                    'leave_year' => $current['year'], 'days' => $days, 'reason' => self::OPENING_BALANCE, 'created_at' => now(), 'updated_at' => now()]);
                $stats['inserted']++;
            } else {
                $stats['updated']++;
            }
        }
        $balances->forget();
    }

    private function stepPay(): void
    {
        $this->sync('loan_providers', 'loan_providers', fn ($r) => [
            'company_id' => $this->companyId, 'name' => $r->name, 'short_name' => $r->short_name, 'description' => $r->description,
            'is_active' => (bool) $r->isActive, 'deleted_at' => $r->deleted_at,
        ], match: fn ($r) => $this->findBy('loan_providers', ['name' => $r->name]));

        $this->sync('allowance_categories', 'allowance_categories', fn ($r) => [
            'company_id' => $this->companyId, 'category' => trim($r->category), 'code' => $r->code,
            'duration' => in_array($r->duration, ['month', 'day', 'night', 'holiday', 'case'], true) ? $r->duration : 'month',
            'generation' => $r->claimed ? 'claim' : 'auto', 'claimed' => (bool) $r->claimed, 'deduct_leave' => (bool) $r->deduct_leave,
            'other_absence' => (bool) $r->other_absence, 'is_active' => (bool) $r->isActive,
        ], match: fn ($r) => $this->findBy('allowance_categories', ['category' => trim($r->category)]), keepOnUpdate: ['taxable', 'description']);

        $this->sync('department_allowances', 'department_allowances', fn ($r) => ($category = $this->ids->get('allowance_categories', $r->category_id)) ? [
            'company_id' => $this->companyId, 'department_id' => $this->ids->get('departments', $r->department_id), 'section_id' => $this->ids->get('departments', $r->section_id),
            'category_id' => $category, 'job_position_id' => $this->ids->get('job_positions', $r->job_position_id), 'amount' => $r->amount ?? 0,
            'total_amount' => $r->total_amount, 'rate_payroll_period' => $r->rate_payroll_period, 'is_active' => (bool) $r->isActive,
        ] : "rate {$r->id}: allowance category {$r->category_id} missing");

        $this->sync('department_overtimes', 'overtime_rates', fn ($r) => [
            'company_id' => $this->companyId, 'department_id' => $this->ids->get('departments', $r->department_id), 'section_id' => $this->ids->get('departments', $r->section_id),
            'job_position_id' => $this->ids->get('job_positions', $r->job_position_id), 'unit' => 'hour', 'amount' => $r->amount ?? 0, 'is_active' => (bool) $r->isActive,
        ]);

        $this->sync('reliefs', 'reliefs', fn ($r) => ($employee = $this->ids->get('employees', $r->employee_id)) ? [
            'employee_id' => $employee, 'amount' => $r->amount ?? 0, 'is_active' => (bool) $r->is_active && (! $r->end_date || $r->end_date >= now()->toDateString()),
            'description' => trim(($r->start_date ? 'From '.$r->start_date : '').($r->end_date ? ' to '.$r->end_date : '')) ?: null,
        ] : "employee {$r->employee_id} not imported");

        $this->sync('saccos_members', 'saccos_members', fn ($r) => ($employee = $this->ids->get('employees', $r->employee_id)) ? [
            'employee_id' => $employee, 'amount' => $r->amount ?? 0, 'total_contribution' => $r->total_contribution ?? 0,
            'from_date' => $this->date($r->from_date), 'is_active' => (bool) $r->isActive,
        ] : "employee {$r->employee_id} not imported");
    }

    private function stepPayroll(): void
    {
        $months = array_flip(array_map(fn ($m) => strtolower(CarbonImmutable::create(2000, $m, 1)->format('F')), range(1, 12)));
        $latest = $this->src->table('payroll_sheets')->max('id');

        $this->sync('payroll_sheets', 'payroll_sheets', function ($r) use ($months, $latest) {
            $month = is_numeric($r->month) ? (int) $r->month : (($months[strtolower(trim((string) $r->month))] ?? -1) + 1);
            if ($month < 1) {
                return "sheet {$r->id}: month \"{$r->month}\" not understood";
            }
            $open = (int) $r->id === (int) $latest && strtolower((string) $r->status) === 'on process';

            return [
                'company_id' => $this->companyId, 'name' => $r->name, 'month' => (string) $month, 'year' => (string) $r->year,
                'payroll_from_date' => $this->date($r->payroll_from_date), 'payroll_to_date' => $this->date($r->payroll_to_date), 'number_of_days' => $r->number_of_days,
                'payroll_cost' => $r->payroll_cost, 'hr_approved' => (bool) $r->hrApproved, 'payroll_approved' => (bool) $r->payrollApproved,
                'director_approved' => (bool) $r->directorAproved, 'status' => $open ? 'draft' : 'approved',
                'approved_at' => $open ? null : ($r->approved_at ?? $r->updated_at), 'generated_at' => $r->created_at,
            ];
        }, match: fn ($r) => DB::table('payroll_sheets')->where('company_id', $this->companyId)->where('year', (string) $r->year)
            ->where('month', (string) (is_numeric($r->month) ? (int) $r->month : (($months[strtolower(trim((string) $r->month))] ?? -1) + 1)))
            ->whereNotIn('id', array_values($this->ids->all('payroll_sheets')))->value('id'));

        $this->bulkSync('payroll_sheet_details', 'payroll_sheet_details', function ($r) {
            $sheet = $this->ids->get('payroll_sheets', $r->payroll_sheet_id);
            $employee = $this->ids->get('employees', $r->employee_id);
            if (! $sheet || ! $employee) {
                return "payslip {$r->id}: sheet or employee missing";
            }
            $n = fn ($v) => round((float) $v, 2);

            return [
                'payroll_sheet_id' => $sheet, 'employee_id' => $employee, 'contract_id' => $this->ids->get('employee_contracts', $r->contract_id),
                'full_name' => $r->full_name, 'job_position' => $r->job_position, 'basic_salary' => $n($r->basic_salary), 'actual_basic_salary' => $n($r->actual_basic_salary),
                'total_allowance' => $n($r->total_allowance), 'total_overtime' => $n($r->total_overtime), 'allowance_overtime' => $n($r->allowance_overtime),
                'leave_deduction' => $n($r->leave_deduction), 'attendance_deduction' => $n($r->attendance_deduction), 'other_deduction' => 0,
                'total_loan' => $n($r->total_loan), 'saccos_serving' => $n($r->saccos_serving), 'nssf' => $n($r->NSSF), 'nssf_employer' => $n($r->NSSF_EMPLOYER),
                'nhif' => $n($r->NHIF), 'nhif_employer' => $n($r->NHIF_EMPLOYER), 'heslb' => $n($r->HESLB), 'tughe' => $n($r->TUGHE), 'amount_taxable' => $n($r->amount_taxable),
                'paye' => $n($r->PAYE), 'ef' => $n($r->EF), 'nic' => $n($r->NIC), 'relief' => $n($r->relief), 'gross_salary' => $n($r->gross_salary),
                'total_deduction' => $n($r->total_deduction), 'net_salary' => $n($r->net_salary), 'wcf' => $n($r->WCF), 'sdl' => $n($r->SDL),
                'allowances' => $r->allowances, 'lines' => json_encode($this->payslipLines($r)), 'bank_id' => $this->ids->get('banks', $r->bank_id),
                'bank_name' => $r->bank_name, 'bank_branch' => $r->bank_branch, 'bank_code' => $r->bank_code, 'bank_account' => $r->bank_account,
                'is_active' => (bool) $r->isActive, 'deleted_at' => $r->deleted_at,
            ];
        });

        foreach (array_values($this->ids->all('payroll_sheets')) as $sheetId) {
            $t = DB::table('payroll_sheet_details')->where('payroll_sheet_id', $sheetId)->whereNull('deleted_at')
                ->selectRaw('count(*) n, sum(gross_salary) g, sum(total_deduction) d, sum(net_salary) net, sum(nssf_employer + nhif_employer + wcf + sdl) er')->first();
            DB::table('payroll_sheets')->where('id', $sheetId)->update(['employees_count' => (int) $t->n, 'gross_total' => round((float) $t->g, 2),
                'deduction_total' => round((float) $t->d, 2), 'net_total' => round((float) $t->net, 2), 'employer_total' => round((float) $t->er, 2)]);
        }

        $this->sync('payroll_comments', 'payroll_comments', fn ($r) => ($sheet = $this->ids->get('payroll_sheets', $r->payroll_sheet_id)) ? [
            'payroll_sheet_id' => $sheet, 'employee_id' => $this->ids->get('employees', $r->employee_id), 'comment' => $r->comment, 'remarks' => $r->remarks,
        ] : "comment {$r->id}: sheet missing");
    }

    /** Itemised payslip lines rebuilt from the HRMS totals (gross = basic + allowances + overtime + relief). */
    private function payslipLines(object $r): array
    {
        $lines = [];
        $add = function (string $group, string $code, string $label, mixed $amount) use (&$lines) {
            $amount = round((float) $amount, 2);
            if (abs($amount) >= 0.01) {
                $lines[] = ['group' => $group, 'code' => $code, 'label' => $label, 'amount' => $amount];
            }
        };
        $basic = (float) $r->basic_salary;
        $full = (float) $r->actual_basic_salary;
        $add('earning', 'BASIC', 'Basic salary'.($full > 0 && $basic < $full - 1 ? ' (of '.number_format($full, 0).')' : ''), $basic);
        $add('earning', 'ALLOWANCE', 'Allowances', $r->total_allowance);
        $add('earning', 'OVERTIME', 'Overtime', $r->total_overtime);
        $add('earning', 'RELIEF', 'Relief', $r->relief);
        foreach ([['PAYE', 'PAYE', $r->PAYE], ['NSSF', 'NSSF', $r->NSSF], ['NHIF', 'NHIF', $r->NHIF], ['HESLB', 'HESLB loan', $r->HESLB], ['TUGHE', 'TUGHE', $r->TUGHE],
            ['NIC', 'NIC', $r->NIC], ['FUND', 'Employee fund', $r->EF], ['SACCOS', 'SACCOS', $r->saccos_serving], ['LOAN', 'Loans', $r->total_loan]] as [$code, $label, $amount]) {
            $add('deduction', $code, $label, $amount);
        }
        foreach ([['NSSF_ER', 'NSSF (employer)', $r->NSSF_EMPLOYER], ['NHIF_ER', 'NHIF (employer)', $r->NHIF_EMPLOYER], ['WCF', 'WCF', $r->WCF], ['SDL', 'SDL', $r->SDL]] as [$code, $label, $amount]) {
            $add('employer', $code, $label, $amount);
        }

        return $lines;
    }

    private function stepTransactions(): void
    {
        $categories = DB::table('allowance_categories')->where('company_id', $this->companyId)->pluck('id', 'category')->mapWithKeys(fn ($id, $name) => [strtolower(trim($name)) => $id])->all();
        $staleBefore = now()->subDays(60)->toDateString();

        $this->sync('emp_allowances', 'emp_allowances', function ($r) use ($categories) {
            $employee = $this->ids->get('employees', $r->employee_id);
            if (! $employee) {
                return "employee {$r->employee_id} not imported";
            }
            $category = $categories[strtolower(trim((string) $r->category))] ?? null;

            return [
                'company_id' => $this->companyId, 'employee_id' => $employee, 'department_allowance_id' => $this->ids->get('department_allowances', $r->department_allowance_id),
                'allowance_category_id' => $category, 'category' => $r->category, 'duration' => $r->duration, 'items' => $r->items, 'amount_per_item' => $r->amount_per_item,
                'amount' => $r->amount ?? 0, 'start_date' => $this->date($r->start_date), 'end_date' => $this->date($r->end_date),
                'hod_approved' => $this->ids->get('employees', $r->hod_approved), 'hr_approved' => $this->ids->get('employees', $r->hr_approved),
                'is_hod_approved' => (bool) $r->is_hod_approved, 'is_hr_approved' => (bool) $r->is_hr_approved, 'hod_comment' => $r->hod_comment, 'hr_comment' => $r->hr_comment,
                'is_active' => (bool) $r->isActive, 'description' => $r->description, 'deleted_at' => $r->deleted_at,
                'status' => $r->status === 'approved' && $r->isActive ? 'approved' : ($r->status === 'pending' ? 'pending' : 'cancelled'),
            ];
        });

        $this->bulkSync('daily_allowances', 'daily_allowances', function ($r) use ($categories) {
            $employee = $this->ids->get('employees', $r->employee_id);
            if (! $employee) {
                return "employee {$r->employee_id} not imported";
            }

            return [
                'company_id' => $this->companyId, 'employee_id' => $employee, 'department_allowance_id' => $this->ids->get('department_allowances', $r->department_allowance_id),
                'allowance_category_id' => $categories[strtolower(trim((string) $r->category))] ?? null,
                'payroll_sheet_detail_id' => $this->ids->get('payroll_sheet_details', $r->payroll_sheet_detail_id),
                'hod_approved' => $this->ids->get('employees', $r->hod_approved), 'hr_approved' => $this->ids->get('employees', $r->hr_approved),
                'is_hod_approved' => (bool) $r->is_hod_approved, 'is_hr_approved' => (bool) $r->is_hr_approved, 'hod_comment' => $r->hod_comment, 'hr_comment' => $r->hr_comment,
                'category' => $r->category, 'duration' => $r->duration ?: 'month', 'items' => $r->items, 'amount' => $r->amount ?? 0, 'amount_per_item' => $r->amount_per_item,
                'start_date' => $this->date($r->start_date), 'end_date' => $this->date($r->end_date ?: $r->start_date), 'is_active' => (bool) $r->isActive,
                'description' => $r->description, 'status' => 'approved', 'source' => 'generated', 'deleted_at' => $r->deleted_at,
            ];
        });

        $this->bulkSync('allowance_sheets', 'allowance_sheets', fn ($r) => ($employee = $this->ids->get('employees', $r->employee_id)) ? [
            'employee_id' => $employee, 'payroll_sheet_detail_id' => $this->ids->get('payroll_sheet_details', $r->payroll_sheet_detail_id),
            'emp_allowance_id' => $this->ids->get('emp_allowances', $r->emp_allowance_id), 'daily_allowance_id' => $this->ids->get('daily_allowances', $r->daily_allowance_id),
            'allowance_name' => $r->allowance_name, 'comment' => $r->comment, 'amount' => $r->amount ?? 0,
        ] : "employee {$r->employee_id} not imported");

        $paidOvertime = $this->src->table('overtime_sheets')->pluck('overtime_id')->flip()->all();
        $this->sync('overtimes', 'overtimes', function ($r) use ($staleBefore, $paidOvertime) {
            $employee = $this->ids->get('employees', $r->employee_id);
            if (! $employee) {
                return "employee {$r->employee_id} not imported";
            }
            $stale = $r->status === 'pending' && $r->date_of_task_done < $staleBefore;

            return [
                'company_id' => $this->companyId, 'employee_id' => $employee, 'department_id' => $this->ids->get('departments', $r->department_id),
                'section_id' => $this->ids->get('departments', $r->section_id), 'hod_approved' => $this->ids->get('employees', $r->hod_approved),
                'hr_approved' => $this->ids->get('employees', $r->hr_approved), 'is_hod_approved' => (bool) $r->is_hod_approved, 'is_hr_approved' => (bool) $r->is_hr_approved,
                'is_paid' => (bool) $r->isPaid || isset($paidOvertime[$r->id]), 'hours' => (int) $r->hours, 'minutes' => (int) $r->minutes,
                'overtime_minutes' => (int) $r->hours * 60 + (int) $r->minutes, 'rate_unit' => 'hour', 'rate_amount' => $r->amount_per_duration,
                'amount_per_minute' => $r->amount_per_minute, 'amount' => $r->amount ?? 0, 'task_title' => $r->task_title ?: 'Overtime',
                'date_of_task_done' => $this->date($r->date_of_task_done), 'from_datetime' => $r->from_datetime, 'to_datetime' => $r->to_datetime,
                'type' => 'overtime', 'description' => $r->description, 'hod_comment' => $r->hod_comment, 'hr_comment' => $r->hr_comment,
                'status' => $stale ? 'cancelled' : ($r->status === 'approved' ? 'approved' : 'pending'), 'source' => $r->attendance_checkin ? 'attendance' : 'employee',
                'decision_comment' => $stale ? 'Not approved in HRMS' : null, 'is_active' => (bool) $r->isActive,
            ];
        });
        $open = $this->src->table('overtimes')->where('status', 'pending')->where('date_of_task_done', '>=', $staleBefore)->count();
        if ($open) {
            $this->note('overtimes', "{$open} recent claims still pending: approve or reject them in HRMS before the switch-over");
        }

        $this->sync('overtime_sheets', 'overtime_sheets', fn ($r) => ($employee = $this->ids->get('employees', $r->employee_id)) ? [
            'employee_id' => $employee, 'payroll_sheet_detail_id' => $this->ids->get('payroll_sheet_details', $r->payroll_sheet_detail_id),
            'overtime_id' => $this->ids->get('overtimes', $r->overtime_id), 'date_of_task_done' => $this->date($r->date_of_task_done), 'amount' => $r->amount ?? 0,
        ] : "employee {$r->employee_id} not imported");

        $this->sync('employee_loans', 'employee_loans', fn ($r) => ($employee = $this->ids->get('employees', $r->employee_id)) ? [
            'company_id' => $this->companyId, 'employee_id' => $employee, 'loan_provider_id' => $this->ids->get('loan_providers', $r->loan_provider_id),
            'updated_by' => $this->userOfEmployee($r->updated_by), 'date_of_loan' => $this->date($r->date_of_loan), 'amount' => $r->amount ?? 0,
            'instalment' => $r->instalment ?? 0, 'returned' => $r->returned ?? 0, 'remain' => $r->remain ?? 0, 'end_date' => $this->date($r->end_date),
            'is_active' => (bool) $r->isActive, 'status' => in_array($r->status, ['enabled', 'disabled', 'paused'], true) ? $r->status : 'disabled', 'deleted_at' => $r->deleted_at,
        ] : "employee {$r->employee_id} not imported");

        $this->sync('loan_histories', 'loan_histories', function ($r) {
            $employee = $this->ids->get('employees', $r->employee_id);
            $sheet = $this->ids->get('payroll_sheets', $r->payroll_sheet_id);
            $detail = $this->ids->get('payroll_sheet_details', $r->payroll_sheet_detail_id);
            if (! $employee || ! $sheet || ! $detail) {
                return "repayment {$r->id}: payroll sheet {$r->payroll_sheet_id} or its payslip no longer exists in HRMS";
            }

            return ['employee_id' => $employee, 'payroll_sheet_id' => $sheet, 'payroll_sheet_detail_id' => $detail, 'employee_loan_id' => $this->ids->get('employee_loans', $r->employee_loan_id),
                'loan_provider' => $r->loan_provider, 'repayment_date' => $this->date($r->repayment_date), 'repaid_amount' => $r->repaid_amount ?? 0,
                'instalment' => $r->instalment ?? 0, 'principle_amount' => $r->principle_amount ?? 0, 'outstanding_loan' => $r->out_standing_loan ?? 0,
                'status' => 'applied', 'deleted_at' => $r->deleted_at];
        });

        $this->sync('employee_mothly_advances', 'employee_monthly_advances', fn ($r) => ($employee = $this->ids->get('employees', $r->employee_id)) ? [
            'company_id' => $this->companyId, 'employee_id' => $employee, 'amount' => $r->amount ?? 0, 'date_advanced' => $this->date($r->date_advanced),
            'month' => (int) substr((string) $r->date_advanced, 5, 2), 'year' => (int) substr((string) $r->date_advanced, 0, 4),
            'status' => 'approved', 'source' => 'hr', 'is_active' => (bool) $r->isActive, 'deleted_at' => $r->deleted_at,
        ] : "employee {$r->employee_id} not imported");

        $this->sync('other_deductions', 'other_deductions', fn ($r) => ($employee = $this->ids->get('employees', $r->employee_id)) ? [
            'company_id' => $this->companyId, 'employee_id' => $employee, 'name' => Str::limit((string) ($r->description ?: 'Deduction'), 100, ''),
            'description' => $r->description, 'amount' => $r->amount ?? 0, 'start_month' => substr((string) $r->from_date, 0, 7), 'months' => $r->duration_month,
            'status' => $r->isActive ? 'approved' : 'stopped', 'deleted_at' => $r->deleted_at,
        ] : "employee {$r->employee_id} not imported");

        $this->sync('salary_adjustments', 'salary_adjustments', fn ($r) => ($employee = $this->ids->get('employees', $r->employee_id)) ? [
            'company_id' => $this->companyId, 'employee_id' => $employee, 'contract_id' => $this->ids->get('employee_contracts', $r->contract_id),
            'adjustment_type' => $r->adjustment_type, 'current_salary' => $r->current_salary ?? 0, 'percentage' => $r->percentage, 'amount' => $r->amount ?? 0,
            'new_salary' => $r->new_salary ?? 0, 'reason' => $r->reason ?: 'other', 'effective_date' => $this->date($r->effective_date),
            'hr_comment' => $r->hr_comment, 'finance_comment' => $r->finance_comment, 'director_comment' => $r->director_comment, 'description' => $r->description,
            'status' => $r->status === 'pending' ? 'cancelled' : $r->status, 'is_active' => (bool) $r->is_active,
        ] : "employee {$r->employee_id} not imported");
        if ($pending = $this->src->table('salary_adjustments')->where('status', 'pending')->count()) {
            $this->note('salary_adjustments', "{$pending} pending in HRMS imported as cancelled history; enter any still wanted in the ERP");
        }

        $this->bulkSync('saccos_contributions', 'saccos_contributions', fn ($r) => ($member = $this->ids->get('saccos_members', $r->saccos_member_id)) ? [
            'saccos_member_id' => $member, 'payroll_sheet_detail_id' => $this->ids->get('payroll_sheet_details', $r->payroll_sheet_detail_id),
            'amount' => $r->amount ?? 0, 'date_contributed' => $this->date($r->date_contributed),
        ] : "member {$r->saccos_member_id} not imported");
    }

    /**
     * For large tables: new rows are inserted in chunks, rows unchanged since the last copy (by `updated_at`) are skipped.
     *
     * @param  Closure(object): (array<string, mixed>|string)  $map
     */
    private function bulkSync(string $from, string $to, Closure $map, int $chunk = 2000, array $unique = []): void
    {
        $stats = &$this->stats($from);
        $mapped = $this->ids->all($from);
        $stamps = $this->ids->stamps($from);
        $required = $this->required($to);
        $defaults = collect(Schema::getColumns($to))->where('nullable', false)->filter(fn ($c) => $c['default'] !== null)
            ->mapWithKeys(fn ($c) => [$c['name'] => trim((string) $c['default'], "'")])->all();

        $this->src->table($from)->orderBy('id')->chunkById($chunk, function ($rows) use (&$stats, $from, $to, $map, $mapped, $stamps, $required, $unique, $defaults) {
            $new = [];
            $links = [];
            foreach ($rows as $row) {
                $stats['read']++;
                $stamp = (string) ($row->updated_at ?? $row->created_at ?? '');
                if (isset($mapped[$row->id]) && $stamp !== '' && ($stamps[$row->id] ?? '') >= $stamp) {
                    $stats['unchanged']++;

                    continue;
                }
                $data = $map($row);
                if (is_string($data)) {
                    $stats['skipped']++;
                    $this->note($from, $data);

                    continue;
                }
                foreach ($defaults as $c => $default) {
                    if (array_key_exists($c, $data) && $data[$c] === null) {
                        $data[$c] = $default;
                    }
                }
                if ($missing = array_filter($required, fn ($c) => array_key_exists($c, $data) && $data[$c] === null)) {
                    $stats['skipped']++;
                    $this->note($from, "row {$row->id}: missing ".implode(', ', $missing));

                    continue;
                }
                $data += ['created_at' => $row->created_at ?? now(), 'updated_at' => $row->updated_at ?? now()];
                if (isset($mapped[$row->id])) {
                    DB::table($to)->where('id', $mapped[$row->id])->update($data);
                    $this->ids->touch($from, (int) $row->id, $stamp ?: null);
                    $stats['updated']++;

                    continue;
                }
                $new[] = [$row->id, $data, $stamp ?: null];
            }
            if (! $new) {
                return;
            }
            $key = fn (array $d) => implode('|', array_map(fn ($c) => (string) ($d[$c] ?? ''), $unique));
            $taken = [];
            if ($unique) {
                $query = DB::table($to)->select(array_merge(['id'], $unique));
                foreach ($unique as $c) {
                    $query->whereIn($c, array_values(array_unique(array_map(fn ($n) => $n[1][$c] ?? null, $new))));
                }
                foreach ($query->get() as $existing) {
                    $taken[$key((array) $existing)] = (int) $existing->id;
                }
            }
            $next = (int) DB::table($to)->max('id') + 1;
            $columns = array_keys(array_merge(...array_map(fn ($n) => $n[1], $new)));
            $insert = [];
            foreach ($new as [$legacy, $data, $stamp]) {
                if ($unique && isset($taken[$key($data)])) {
                    $target = $taken[$key($data)];
                    $pending = array_search($target, array_column($insert, 'id'), true);
                    $pending !== false ? $insert[$pending] = ['id' => $target] + array_merge(array_fill_keys($columns, null), $data) : DB::table($to)->where('id', $target)->update($data);
                    $links[] = ['legacy' => (int) $legacy, 'target' => $target, 'stamp' => $stamp];
                    $stats['updated']++;

                    continue;
                }
                $id = $next++;
                $insert[] = ['id' => $id] + array_merge(array_fill_keys($columns, null), $data);
                $links[] = ['legacy' => (int) $legacy, 'target' => $id, 'stamp' => $stamp];
                if ($unique) {
                    $taken[$key($data)] = $id;
                }
            }
            foreach (array_chunk($insert, 500) as $part) {
                DB::table($to)->insert($part);
            }
            $this->ids->insertMany($from, $links);
            $stats['inserted'] += count($insert);
        });
    }

    private function stepRoster(): void
    {
        $time = fn ($t) => $t && preg_match('/^\d{1,2}:\d{2}/', (string) $t) ? substr((string) $t, 0, 5) : null;

        $this->sync('roster_codes', 'roster_codes', fn ($r) => [
            'company_id' => $this->companyId, 'code' => trim($r->code), 'duty' => trim($r->duty), 'description' => $r->description,
            'can_punch' => (bool) $r->can_punch, 'pass_day' => (bool) $r->pass_day, 'start_time' => $time($r->start_time), 'end_time' => $time($r->end_time),
            'work_hour' => $r->work_hour ?? 0, 'is_active' => true,
        ], match: fn ($r) => $this->findBy('roster_codes', ['code' => trim($r->code)]), keepOnUpdate: ['color', 'break_minutes', 'is_active']);

        $this->sync('public_holidays', 'public_holidays', fn ($r) => [
            'company_id' => $this->companyId, 'title' => $r->title, 'day_number' => $r->day_number, 'month_number' => $r->month_number, 'is_active' => (bool) $r->isActive,
        ], match: fn ($r) => DB::table('public_holidays')->where('company_id', $this->companyId)->where('day_number', $r->day_number)
            ->where('month_number', $r->month_number)->whereNotIn('id', array_values($this->ids->all('public_holidays')))->value('id'));

        $departments = DB::table('employees')->where('company_id', $this->companyId)->pluck('department_id', 'id')->all();
        $rosters = DB::table('rosters')->where('company_id', $this->companyId)->where('type', 'duty')->where('title', 'like', 'HRMS duties %')
            ->get(['id', 'department_id', 'from_date'])->mapWithKeys(fn ($r) => [$r->department_id.'|'.substr((string) $r->from_date, 0, 7) => $r->id])->all();
        $rosterFor = function (int $departmentId, string $date) use (&$rosters): int {
            $key = $departmentId.'|'.substr($date, 0, 7);
            if (! isset($rosters[$key])) {
                $month = CarbonImmutable::parse(substr($date, 0, 7).'-01');
                $rosters[$key] = DB::table('rosters')->insertGetId([
                    'company_id' => $this->companyId, 'department_id' => $departmentId, 'title' => 'HRMS duties '.$month->format('M Y'), 'type' => 'duty',
                    'from_date' => $month->toDateString(), 'to_date' => $month->endOfMonth()->toDateString(), 'roster_year' => (string) $month->year,
                    'status' => 'published', 'published_at' => now(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $rosters[$key];
        };

        $this->bulkSync('employee_timetables', 'employee_timetables', function ($r) use ($time, $departments, $rosterFor) {
            $employee = $this->ids->get('employees', $r->employee_id);
            $date = $this->date($r->day_date);
            if (! $employee || ! $date || $date < '2000-01-01' || $date > '2100-01-01') {
                return "duty {$r->id}: employee missing or date {$r->day_date} not valid";
            }
            $leave = in_array($r->source, ['annual_leave', 'other_leave'], true);
            $department = $departments[$employee] ?? null;
            if (! $leave && ! $department) {
                return "duty {$r->id}: employee has no department";
            }

            return [
                'company_id' => $this->companyId, 'employee_id' => $employee, 'roster_id' => $leave ? null : $rosterFor((int) $department, $date),
                'roster_code_id' => $this->ids->get('roster_codes', $r->roster_code_id), 'day_date' => $date, 'code' => trim((string) $r->code) ?: '-',
                'duty' => trim((string) $r->duty) ?: '-', 'can_punch' => (bool) $r->can_punch, 'pass_day' => (bool) $r->pass_day,
                'start_time' => $time($r->start_time), 'end_time' => $time($r->end_time), 'source' => $leave ? 'leave' : 'roster',
                'overtime' => (bool) $r->overtime, 'max_overtime_hours' => $r->max_overtime_hours,
            ];
        }, unique: ['employee_id', 'day_date', 'source']);
    }

    private function stepAttendance(): void
    {
        $this->sync('attendance_claim_reasons', 'attendance_claim_reasons', fn ($r) => [
            'company_id' => $this->companyId, 'reason' => $r->reason, 'description' => $r->description, 'is_active' => (bool) $r->isActive,
        ], match: fn ($r) => $this->findBy('attendance_claim_reasons', ['reason' => $r->reason]));

        $status = fn ($r) => match (strtolower(trim((string) $r->status))) {
            'present' => (int) $r->punch_in_late_minutes > 0 ? 'Late' : 'Present',
            'absent' => 'Absent',
            'on leave', 'leave(l)' => 'On leave',
            default => $r->status,
        };
        $this->bulkSync('attendance_transas', 'attendance_transas', fn ($r) => ($employee = $this->ids->get('employees', $r->employee_id)) ? [
            'company_id' => $this->companyId, 'employee_id' => $employee, 'contract_id' => $this->ids->get('employee_contracts', $r->contract_id),
            'timetable_detail' => $r->timetable_detail, 'transaction_date' => $this->date($r->transaction_date), 'pass_day' => (bool) $r->pass_day,
            'attendance_in_time' => $r->attendance_in_time, 'punch_in_time' => $r->punch_in_time, 'punch_out_time' => $r->punch_out_time,
            'attendance_out_time' => $r->attendance_out_time, 'punch_in_time_loss' => $r->punch_in_time_loss, 'punch_out_time_loss' => $r->punch_out_time_loss,
            'worktime_hour' => $r->worktime_hour, 'worked_hour' => $r->worked_hour, 'basic_salary' => $r->basic_salary, 'amount_per_day' => $r->amount_per_day,
            'amount_earned_per_day' => $r->amount_earned_per_day, 'actual_hour' => $r->actual_hour, 'deduct_leave' => (bool) $r->deduct_leave,
            'lost_hour' => $r->lost_hour, 'lost_amount' => $r->lost_amount, 'percent' => $r->percent, 'punch_out_status' => $r->punch_out_status,
            'punch_in_status' => $r->punch_in_status, 'status' => $status($r), 'area_alias' => $r->area_alias, 'in_area_alias' => $r->in_area_alias,
            'punch_in_late_minutes' => $r->punch_in_late_minutes, 'punch_in_late_hour' => $r->punch_in_late_hour, 'punch_out_late_minutes' => $r->punch_out_late_minutes,
            'punch_out_late_hour' => $r->punch_out_late_hour, 'overtime_hr' => $r->overtime_hr, 'overtime_minutes' => $r->overtime_minutes,
            'has_overtime' => (bool) $r->hasOvertime, 'is_claimed' => (bool) $r->isClaimed, 'description' => $r->description, 'payroll_date_remark' => $r->payroll_date_remark,
        ] : "employee {$r->employee_id} not imported", unique: ['employee_id', 'transaction_date']);

        $this->sync('attendance_claims', 'attendance_claims', function ($r) {
            $day = $this->ids->get('attendance_transas', $r->attendancy_transa_id);
            $employee = $this->ids->get('employees', $r->employee_id);
            if (! $day || ! $employee) {
                return "claim {$r->id}: attendance day or employee missing";
            }

            return [
                'attendance_transa_id' => $day, 'employee_id' => $employee, 'claim_reason_id' => $this->ids->get('attendance_claim_reasons', $r->claim_reason_id),
                'approved_by' => $this->ids->get('employees', $r->approved_by), 'requested_by' => $this->userOfEmployee($r->employee_id),
                'claim_date' => $this->date($r->claim_date ?: $r->created_at), 'approved_at' => $r->approved_at, 'set_lost_hr_zero' => (bool) $r->setLostHrZero,
                'hr_accepted' => $r->hr_accepted, 'lost_hour' => $r->lost_hour, 'lost_amount' => $r->lost_amount, 'percent' => $r->percent, 'remark' => $r->remark,
                'description' => $r->description ?: ($r->remark ?: 'Claim from HRMS'), 'comment' => $r->comment,
                'claim_status' => ['pending' => 'pending', 'approved' => 'approved', 'rejected' => 'rejected'][strtolower((string) $r->claim_status)] ?? 'pending',
            ];
        });
        if ($pending = $this->src->table('attendance_claims')->where('claim_status', 'Pending')->count()) {
            $this->note('attendance_claims', "{$pending} claims pending in HRMS: decide them in HRMS before the switch-over (they have no ERP approval request)");
        }

        $today = now()->toDateString();
        $this->sync('excuse_of_duties', 'excuse_of_duties', fn ($r) => ($employee = $this->ids->get('employees', $r->employee_id)) ? [
            'company_id' => $this->companyId, 'employee_id' => $employee, 'start_date' => $this->date($r->start_date), 'end_date' => $this->date($r->end_date ?: $r->start_date),
            'number_of_days' => $r->number_of_days ?? 1, 'hospital_name' => $r->hospital_name, 'doctor_name' => $r->doctor_name, 'patient_address' => $r->patient_address,
            'reason' => $r->reason, 'status' => $this->date($r->end_date) >= $today ? 'active' : 'ended',
        ] : "employee {$r->employee_id} not imported");

        $this->sync('admitted_employees', 'admitted_employees', fn ($r) => ($employee = $this->ids->get('employees', $r->employee_id)) ? [
            'company_id' => $this->companyId, 'employee_id' => $employee, 'start_date' => $this->date($r->admission_date), 'end_date' => $this->date($r->discharge_date ?: $r->admission_date),
            'number_of_days' => $r->number_of_days ?? 1, 'hospital_name' => $r->hospital_name, 'admission_reason' => $r->admission_reason,
            'release_reason' => $r->release_reason, 'remark' => $r->remark, 'status' => $this->date($r->discharge_date) >= $today ? 'active' : 'ended',
        ] : "employee {$r->employee_id} not imported");
    }

    private function stepArchive(): void
    {
        $this->sync('employee_patient_ids', 'employee_patient_ids', fn ($r) => ($employee = $this->ids->get('employees', $r->employee_id)) ? [
            'company_id' => $this->companyId, 'employee_id' => $employee, 'patient_id' => trim((string) $r->patient_id),
        ] : "employee {$r->employee_id} not imported");

        $months = array_flip(array_map(fn ($m) => strtolower(CarbonImmutable::create(2000, $m, 1)->format('F')), range(1, 12)));
        $this->sync('uploaded_payrolls', 'uploaded_payrolls', function ($r) use ($months) {
            $month = is_numeric($r->of_month) ? (int) $r->of_month : (($months[strtolower(trim((string) $r->of_month))] ?? -1) + 1);

            return $month >= 1 ? ['company_id' => $this->companyId, 'uploaded_by' => $this->ids->get('employees', $r->employee_id), 'month' => $month,
                'year' => (int) $r->of_year, 'file_name' => $r->fileName] : "upload {$r->id}: month \"{$r->of_month}\" not understood";
        });

        $byNumber = DB::table('employees')->where('company_id', $this->companyId)->pluck('id', 'employee_no')->all();
        $this->bulkSync('uploaded_payroll_sheets', 'uploaded_payroll_rows', function ($r) use ($byNumber) {
            $upload = $this->ids->get('uploaded_payrolls', $r->uploaded_payrolly_id);
            if (! $upload) {
                return "row {$r->id}: upload {$r->uploaded_payrolly_id} missing";
            }
            $data = collect((array) $r)->except(['id', 'employee_id', 'uploaded_payrolly_id', 'created_at', 'updated_at'])->all();
            $number = trim((string) $r->ST_NO);

            return [
                'uploaded_payroll_id' => $upload, 'employee_id' => $this->ids->get('employees', $r->employee_id) ?? ($byNumber[$number] ?? null),
                'employee_no' => $number ?: null, 'gross_salary' => is_numeric($r->GROSS_SALARY) ? $r->GROSS_SALARY : null,
                'net_pay' => is_numeric($r->NET_PAY) ? $r->NET_PAY : null, 'data' => json_encode($data),
            ];
        });
    }

    private function stepRoles(): void
    {
        $permissions = Permission::pluck('name')->all();
        $hrmsPermissions = $this->src->table('roles_permissions')->join('permissions', 'permissions.id', '=', 'roles_permissions.permission_id')
            ->get(['roles_permissions.role_id', 'permissions.slug'])->groupBy('role_id');
        $stats = &$this->stats('roles');

        $roleIds = [];
        foreach ($this->src->table('roles')->get() as $role) {
            $stats['read']++;
            $slug = strtolower(str_replace(' ', '_', (string) $role->slug));
            $name = self::ROLE_MAP[$slug] ?? $role->name;
            $existing = Role::where('name', $name)->where('guard_name', 'web')->where('company_id', $this->companyId)->first();
            $erpRole = $existing ?? Role::create(['name' => $name, 'guard_name' => 'web', 'company_id' => $this->companyId]);
            $existing ? $stats['updated']++ : $stats['inserted']++;
            if (! isset(self::ROLE_MAP[$slug])) {
                $mapped = $this->mapPermissions($hrmsPermissions->get($role->id, collect())->pluck('slug')->all(), $permissions);
                $erpRole->givePermissionTo(array_merge($mapped, ['self-service.use']));
            }
            $roleIds[$role->id] = $erpRole;
        }

        $assigned = &$this->stats('users_roles');
        $byUser = $this->src->table('users_roles')->get()->groupBy('user_id');
        $direct = $this->src->table('users_permissions')->join('permissions', 'permissions.id', '=', 'users_permissions.permission_id')
            ->get(['users_permissions.user_id', 'permissions.slug'])->groupBy('user_id');
        $employeeRole = Role::where('name', 'employee')->where('company_id', $this->companyId)->first();

        foreach ($this->ids->all('users') as $legacyUser => $userId) {
            $user = User::find($userId);
            if (! $user) {
                continue;
            }
            $roles = collect($byUser->get($legacyUser, []))->map(fn ($ur) => $roleIds[$ur->role_id] ?? null)->filter()->values()->all();
            if ($employeeRole && in_array($legacyUser, $this->employeeUsers, false)) {
                $roles[] = $employeeRole;
            }
            if ($roles) {
                $user->assignRole(collect($roles)->unique('id')->all());
                $assigned['inserted'] += count($roles);
            }
            if ($grants = $this->mapPermissions($direct->get($legacyUser, collect())->pluck('slug')->all(), $permissions)) {
                $user->givePermissionTo($grants);
            }
            $assigned['read']++;
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function stepFiles(): void
    {
        $stats = &$this->stats('files');
        if (! $this->filesPath || ! is_dir($this->filesPath)) {
            $this->note('files', 'no --files folder given, files not copied');

            return;
        }

        $copy = function (string $legacyFolder, ?string $name, int $employeeId, string $folder) use (&$stats): ?string {
            if (! $name) {
                return null;
            }
            $stats['read']++;
            $source = rtrim($this->filesPath, '/')."/employee/{$legacyFolder}/{$name}";
            if (! is_file($source)) {
                $stats['skipped']++;
                $this->note('files', "missing: employee/{$legacyFolder}/{$name}");

                return null;
            }
            $target = "hr/{$this->companyId}/employees/{$employeeId}/{$folder}/".basename($name);
            if (! $this->dryRun && ! Storage::disk(EmployeeFiles::DISK)->exists($target)) {
                Storage::disk(EmployeeFiles::DISK)->put($target, file_get_contents($source));
                $stats['inserted']++;
            }

            return $target;
        };

        foreach ($this->src->table('employees')->whereNotNull('profile_image')->where('profile_image', '!=', '')->where('profile_image', '!=', 'default.jpg')->get(['id', 'profile_image']) as $r) {
            if (($id = $this->ids->get('employees', $r->id)) && ($path = $copy('passport_size', $r->profile_image, $id, 'photo'))) {
                DB::table('employees')->where('id', $id)->update(['profile_image' => $path]);
            }
        }
        foreach ($this->src->table('employee_documents')->get(['id', 'employee_id', 'file_name']) as $r) {
            if (($id = $this->ids->get('employees', $r->employee_id)) && ($path = $copy('document', $r->file_name, $id, 'documents'))) {
                DB::table('employee_documents')->where('id', $this->ids->get('employee_documents', $r->id))->update(['file_path' => $path]);
            }
        }
        foreach ($this->src->table('employee_licences')->get(['id', 'employee_id', 'file_name']) as $r) {
            if (($id = $this->ids->get('employees', $r->employee_id)) && ($path = $copy('licences', $r->file_name, $id, 'licences'))) {
                DB::table('employee_licences')->where('id', $this->ids->get('employee_licences', $r->id))->update(['file_path' => $path]);
            }
        }
        foreach ($this->src->table('employee_dependants')->get(['id', 'employee_id', 'birth_certificate']) as $r) {
            if (($id = $this->ids->get('employees', $r->employee_id)) && ($path = $copy('dependat', $r->birth_certificate, $id, 'dependants'))) {
                DB::table('employee_dependants')->where('id', $this->ids->get('employee_dependants', $r->id))->update(['birth_certificate' => $path, 'birth_certificate_name' => basename($r->birth_certificate)]);
            }
        }
    }

    /**
     * @param  Closure(object): (array<string, mixed>|string)  $map  target columns, or a reason to skip the row
     * @param  Closure(object): (int|null)|null  $match  existing ERP row to link on the first run
     * @param  list<string>  $keepOnUpdate  columns written only when the row is inserted
     */
    private function sync(string $from, string $to, Closure $map, ?Closure $query = null, ?Closure $match = null, array $keepOnUpdate = [], ?string $key = null): void
    {
        $key ??= $from;
        $stats = &$this->stats($key);
        $source = $this->src->table($from);
        $query ? $query($source) : $source->orderBy('id');

        foreach ($source->get() as $row) {
            $stats['read']++;
            $data = $map($row);
            if (is_string($data)) {
                $stats['skipped']++;
                $this->note($key, $data);

                continue;
            }
            $data = array_filter($data, fn ($value, $column) => $value !== null || ! in_array($column, $this->required($to), true), ARRAY_FILTER_USE_BOTH);
            $timestamps = array_filter(['created_at' => $row->created_at ?? null, 'updated_at' => $row->updated_at ?? null]);
            $targetId = $this->ids->get($key, $row->id);
            if ($targetId && ! DB::table($to)->where('id', $targetId)->exists()) {
                $targetId = null;
            }
            $targetId ??= $match ? $match($row) : null;

            try {
                if ($targetId) {
                    DB::table($to)->where('id', $targetId)->update(array_diff_key($data, array_flip($keepOnUpdate)) + array_intersect_key($timestamps, ['updated_at' => 1]));
                    $stats['updated']++;
                } else {
                    $targetId = DB::table($to)->insertGetId($data + $timestamps + ['created_at' => now(), 'updated_at' => now()]);
                    $stats['inserted']++;
                }
            } catch (QueryException $e) {
                $stats['skipped']++;
                $this->note($key, "row {$row->id}: ".Str::limit(strtok($e->getMessage(), '('), 160));

                continue;
            }
            $this->ids->set($key, (int) $row->id, (int) $targetId);
        }
    }

    /** @return list<string> */
    private function required(string $table): array
    {
        return $this->required[$table] ??= collect(Schema::getColumns($table))->where('nullable', false)->pluck('name')->all();
    }

    private function &stats(string $table): array
    {
        $this->report[$table] ??= ['read' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'notes' => []];

        return $this->report[$table];
    }

    private function note(string $table, string $note): void
    {
        $stats = &$this->stats($table);
        if (count($stats['notes']) < 20) {
            $stats['notes'][] = $note;
        }
    }

    private function findBy(string $table, array $where): ?int
    {
        $query = DB::table($table)->where('company_id', $this->companyId);
        foreach ($where as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->whereRaw("LOWER({$column}) = ?", [strtolower((string) $value)]);
        }
        $legacy = array_values($this->ids->all($table === 'professional_licences' ? 'proffesional_licences' : $table));

        return $query->when($legacy, fn ($q) => $q->whereNotIn('id', $legacy))->value('id');
    }

    /** @param list<string> $slugs @param list<string> $known @return list<string> */
    private function mapPermissions(array $slugs, array $known): array
    {
        $names = collect($slugs)->flatMap(fn ($s) => HrmsPermissionMap::MAP[$s] ?? [])->unique()->values()->all();

        return array_values(array_intersect($names, $known));
    }

    private function userOfEmployee(mixed $hrmsEmployeeId): ?int
    {
        return $hrmsEmployeeId ? $this->ids->get('users', $this->employeeUsers[(int) $hrmsEmployeeId] ?? null) : null;
    }

    private function contractStatus(?string $status): string
    {
        $status = strtolower(trim((string) $status));
        $status = self::CONTRACT_STATUS[$status] ?? $status;
        $valid = array_merge(array_keys(O::CONTRACT_STATUSES), array_keys(O::CONTRACT_END_REASONS));

        return in_array($status, $valid, true) ? $status : 'ended';
    }

    private function idType(?string $type): string
    {
        return match (strtolower(trim((string) $type))) {
            'passport' => 'passport',
            'vote id', 'voter id' => 'voter_id',
            'driving licence', 'driving license' => 'driving_licence',
            default => 'nida',
        };
    }

    private function marital(?string $status): ?string
    {
        return match (strtolower(trim((string) $status))) {
            'single' => 'single', 'married' => 'married', 'divorced' => 'divorced', 'separated' => 'separated',
            'widowed', 'widower', 'widow' => 'widowed', default => null,
        };
    }

    private function date(mixed $value): ?string
    {
        if (! $value || str_starts_with((string) $value, '0000')) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function blank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
