<?php

namespace Modules\Hr\Actions;

use App\Models\Employee;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Creates an employee and, if asked, their user account. The invite email is sent after the transaction commits. */
class RegisterEmployee
{
    public function __construct(private readonly CreateEmployeeAccount $accounts) {}

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

    /** Suggests the next employee number from the latest one, keeping its prefix and padding (EMP-0009 → EMP-0010). */
    public function nextNumber(): string
    {
        $last = Employee::withTrashed()->latest('id')->value('employee_no');
        if (! $last || ! preg_match('/^(.*?)(\d+)$/', $last, $m)) {
            $m = [null, 'EMP-', '0000'];
        }
        $width = strlen($m[2]);
        for ($n = (int) $m[2] + 1; ; $n++) {
            $candidate = $m[1] . str_pad((string) $n, $width, '0', STR_PAD_LEFT);
            if (! Employee::withTrashed()->where('employee_no', $candidate)->exists()) {
                return $candidate;
            }
        }
    }
}
