<?php

namespace Modules\Hr\Import;

use App\Models\Company;
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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Copies HRMS data into one ERP company. Every row is matched through `legacy_ids`, so a run inserts new rows and
 * updates the ones copied before; ERP rows that did not come from HRMS are never touched.
 */
class HrmsImporter
{
    public const STEPS = ['reference', 'organisation', 'users', 'employees', 'contracts', 'profiles', 'statutory', 'structure', 'roles', 'files'];

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
                'username' => $username, 'email' => $email, 'role_as' => $r->role_as, 'email_verified_at' => $r->email_verified_at,
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
    private function sync(string $from, string $to, Closure $map, ?Closure $query = null, ?Closure $match = null, array $keepOnUpdate = []): void
    {
        $stats = &$this->stats($from);
        $source = $this->src->table($from);
        $query ? $query($source) : $source->orderBy('id');

        foreach ($source->get() as $row) {
            $stats['read']++;
            $data = $map($row);
            if (is_string($data)) {
                $stats['skipped']++;
                $this->note($from, $data);

                continue;
            }
            $data = array_filter($data, fn ($value, $column) => $value !== null || ! in_array($column, $this->required($to), true), ARRAY_FILTER_USE_BOTH);
            $timestamps = array_filter(['created_at' => $row->created_at ?? null, 'updated_at' => $row->updated_at ?? null]);
            $targetId = $this->ids->get($from, $row->id);
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
                $this->note($from, "row {$row->id}: ".Str::limit(strtok($e->getMessage(), '('), 160));

                continue;
            }
            $this->ids->set($from, (int) $row->id, (int) $targetId);
        }
    }

    /** @return list<string> */
    private function required(string $table): array
    {
        return $this->required[$table] ??= collect(Schema::getColumns($table))->where('nullable', false)->pluck('name')->all();
    }

    private function &stats(string $table): array
    {
        $this->report[$table] ??= ['read' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'notes' => []];

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
