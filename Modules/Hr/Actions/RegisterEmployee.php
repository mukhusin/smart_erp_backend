<?php

namespace Modules\Hr\Actions;

use App\Models\Employee;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Hr\Support\EmployeeNumbers;

/** Creates an employee and, if asked, their user account. The invite email is sent after the transaction commits. */
class RegisterEmployee
{
    public function __construct(private readonly CreateEmployeeAccount $accounts, private readonly EmployeeNumbers $numbers) {}

    /** @return array{employee: Employee, invite_sent: bool|null} */
    public function handle(array $data): array
    {
        $account = Arr::pull($data, 'account');

        $employee = DB::transaction(function () use ($data, $account) {
            if (! empty($data['probation_start']) && ! empty($data['probation_period'])) {
                $data['probation_end'] = now()->parse($data['probation_start'])->addMonthsNoOverflow((int) $data['probation_period'])->subDay()->toDateString();
            }
            $employee = Employee::create($data + ['updated_by' => auth()->user()?->username]);

            if ($account) {
                $this->accounts->create($employee, $account['username'] ?? null);
            }

            return $employee;
        });

        return [
            'employee' => $employee,
            'invite_sent' => $account ? $this->accounts->invite($employee->refresh()) : null,
        ];
    }

    /** Next number in the chosen number format, the default one, or (without formats) the HRMS series of the employee type. */
    public function nextNumber(?int $formatId = null, ?int $departmentId = null, ?string $empType = null): string
    {
        return $this->numbers->next($formatId, $departmentId, $empType);
    }
}
