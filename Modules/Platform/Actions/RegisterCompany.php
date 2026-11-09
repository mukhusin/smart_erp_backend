<?php

namespace Modules\Platform\Actions;

use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobPosition;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\ModuleAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Core\Support\DefaultRoles;

/**
 * Registers a client company (tenant) in one transaction:
 * company → branches (one head office) → enabled modules → default roles →
 * HR department and position → first HR admin as employee + user (role company-admin).
 * The HR admin then gets an invite email to set their own password (SendCompanyInvite).
 */
class RegisterCompany
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly ModuleAccessService $modules,
    ) {}

    /**
     * @param array{company: array, branches: list<array>, modules?: list<array{slug: string, expires_at?: ?string}>, admin: array} $data
     */
    public function handle(array $data): Company
    {
        return DB::transaction(function () use ($data) {
            $company = Company::create($data['company'] + ['is_active' => true]);

            foreach ($data['modules'] ?? [] as $module) {
                $this->modules->enableModule($company->id, $module['slug'], isset($module['expires_at']) ? new \DateTimeImmutable($module['expires_at']) : null);
            }

            DefaultRoles::createForCompany($company);

            $this->context->run($company, function () use ($company, $data) {
                $headOffice = null;
                foreach ($data['branches'] as $i => $branch) {
                    $isHq = (bool) ($branch['is_headquarters'] ?? false);
                    $created = CompanyBranch::create($branch + ['is_headquarters' => $isHq, 'is_active' => true]);
                    $headOffice ??= $isHq ? $created : null;
                }
                $headOffice ??= $company->branches()->orderBy('id')->first()->forceFill(['is_headquarters' => true]);
                $headOffice->save();

                $department = Department::create([
                    'company_branch_id' => $headOffice->id,
                    'name' => 'Human Resources',
                    'short_name' => 'HR',
                    'is_active' => true,
                ]);
                $position = JobPosition::create([
                    'department_id' => $department->id,
                    'title' => $data['admin']['position'] ?? 'HR Manager',
                    'is_active' => true,
                ]);

                $admin = $data['admin'];
                $user = User::create([
                    'company_id' => $company->id,
                    'company_branch_id' => $headOffice->id,
                    'username' => $admin['username'] ?? Str::lower($admin['email']),
                    'email' => Str::lower($admin['email']),
                    // Unknown random password until the admin sets their own from the invite link.
                    'password' => Hash::make(Str::password(40)),
                    'is_active' => true,
                ]);
                Employee::create([
                    'company_branch_id' => $headOffice->id,
                    'department_id' => $department->id,
                    'job_position_id' => $position->id,
                    'user_id' => $user->id,
                    'employee_no' => $admin['employee_no'] ?? 'EMP-0001',
                    'fname' => $admin['fname'],
                    'mname' => $admin['mname'] ?? null,
                    'lname' => $admin['lname'],
                    'gender' => $admin['gender'],
                    'phone_number' => $admin['phone_number'] ?? null,
                    'email' => Str::lower($admin['email']),
                    'employment_status' => 'active',
                ]);
                $user->assignRole(DefaultRoles::COMPANY_ADMIN);
            });

            return $company->refresh();
        });
    }
}
