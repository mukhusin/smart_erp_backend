<?php

namespace Modules\Hr\Actions;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

class UpdateEmployee
{
    public function handle(Employee $employee, array $data, ?string $by): Employee
    {
        $data['probation_end'] = ! empty($data['probation_start']) && ! empty($data['probation_period'])
            ? now()->parse($data['probation_start'])->addMonthsNoOverflow((int) $data['probation_period'])->subDay()->toDateString()
            : null;

        return DB::transaction(function () use ($employee, $data, $by) {
            $employee->update($data + ['updated_by' => $by]);

            if ($user = $employee->user) {
                $user->forceFill([
                    'email' => $employee->email ? strtolower($employee->email) : $user->email,
                    'company_branch_id' => $employee->company_branch_id,
                ])->save();
            }

            return $employee;
        });
    }
}
