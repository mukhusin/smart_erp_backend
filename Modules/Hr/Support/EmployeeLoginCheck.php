<?php

namespace Modules\Hr\Support;

use App\Models\CompanyModule;
use App\Models\User;
use App\Services\CompanyContext;
use Modules\Core\Contracts\LoginCheck;
use Modules\Core\Support\DefaultRoles;
use Modules\Hr\Actions\ManageContracts;
use Modules\Hr\Actions\ManagePlacements;

/**
 * HRMS rule: staff log in only while they have an active contract (interns and residents: an active period).
 * Company admins are exempt so a new company is never locked out; companies without the HR module are not checked.
 */
class EmployeeLoginCheck implements LoginCheck
{
    public const NO_EMPLOYEE = 'No employee record is linked to this account. Contact HR.';

    public const NO_CONTRACT = "You don't have an active contract, or your contract has ended. Contact HR.";

    public function __construct(
        private readonly ManageContracts $contracts,
        private readonly ManagePlacements $placements,
        private readonly CompanyContext $context,
    ) {}

    public function deny(User $user): ?string
    {
        if (! $user->company_id || ! CompanyModule::where('company_id', $user->company_id)->where('module_slug', 'hr')->where('is_enabled', true)->exists()) {
            return null;
        }
        setPermissionsTeamId($user->company_id);
        if ($user->hasRole(DefaultRoles::COMPANY_ADMIN)) {
            return null;
        }

        $employee = $user->employee;
        if (! $employee) {
            return self::NO_EMPLOYEE;
        }

        return $this->context->run($user->company_id, function () use ($employee) {
            $this->contracts->sync($employee);
            $this->placements->sync($employee);
            $active = $employee->contracts()->where('is_active', true)->exists()
                || $employee->placements()->where('status', 'active')->exists();

            return $active ? null : self::NO_CONTRACT;
        });
    }
}
