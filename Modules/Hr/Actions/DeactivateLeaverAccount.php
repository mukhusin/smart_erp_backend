<?php

namespace Modules\Hr\Actions;

use App\Models\Employee;
use Illuminate\Support\Facades\Log;
use Modules\Admin\Actions\ChangeUserAccess;
use Modules\Hr\Support\EmployeeOptions as O;

/** Deactivates the user account of an employee who has left, once their leaving date has come. */
class DeactivateLeaverAccount
{
    public function __construct(private readonly ChangeUserAccess $access) {}

    /** @return bool false when the account was kept because it is the only company admin */
    public function handle(Employee $employee): bool
    {
        $user = $employee->user;
        if (! $user?->is_active || $employee->employment_status !== 'terminated' || ! $employee->termination_date || $employee->termination_date->isFuture()) {
            return true;
        }

        $reason = 'Left the organization on '.$employee->termination_date->format('d M Y').': '.O::contractStatusLabel($employee->contract_status).'.';
        $done = $this->access->deactivateLeaver($user, $reason);
        if (! $done) {
            Log::warning("Account of leaver {$employee->employee_no} kept active: only company admin.");
        }

        return $done;
    }
}
