<?php

namespace Modules\Hr\Actions;

use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\NatureOfContract;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Hr\Support\EmployeeNumbers;
use Modules\Hr\Support\EmployeeOptions as O;

/**
 * Employee contracts, following the HRMS rules:
 * - one active contract at a time; a renewal is added in the last 90 days and waits until it starts;
 * - end date = start + period months - 1 day;
 * - the active contract sets the employee's type; every change is kept in the contract history;
 * - only the latest contract can be removed (given by mistake), and only before payroll used it;
 * - with "renumber on contract change" on, the active contract's type gives the employee a number in that type's number format;
 * - an employee who leaves loses their user account on the leaving date.
 */
class ManageContracts
{
    public function __construct(
        private readonly EmployeeNumbers $numbers,
        private readonly DeactivateLeaverAccount $leavers,
    ) {}

    public static function endDate(string $start, int $months): CarbonImmutable
    {
        return CarbonImmutable::parse($start)->startOfDay()->addMonthsNoOverflow($months)->subDay();
    }

    /** @param array{nature_of_contract_id: int, basic_salary: numeric, start_date: string, period: int, has_annual_leave?: bool, comment?: string|null} $data */
    public function give(Employee $employee, array $data, ?User $by): EmployeeContract
    {
        if ($employee->employment_status === 'terminated') {
            throw ValidationException::withMessages(['start_date' => 'This employee has left the organization.']);
        }

        return DB::transaction(function () use ($employee, $data, $by) {
            if ($employee->waitingContract()->exists()) {
                throw ValidationException::withMessages(['start_date' => 'This employee already has a contract waiting to start.']);
            }

            $start = CarbonImmutable::parse($data['start_date'])->startOfDay();
            $end = self::endDate($data['start_date'], (int) $data['period']);
            if ($active = $employee->activeContract()->first()) {
                $this->assertRenewal($active, $start);
            }

            $today = CarbonImmutable::today();
            $status = $start->gt($today) ? 'waiting' : ($end->lt($today) ? 'ended' : 'active');

            $contract = $employee->contracts()->create([
                'nature_of_contract_id' => $data['nature_of_contract_id'],
                'contract_no' => $employee->employee_no,
                'basic_salary' => $data['basic_salary'],
                'period' => $data['period'],
                'start_date' => $start,
                'end_date' => $end,
                'comment' => $data['comment'] ?? null,
                'status' => $status,
                'is_active' => $status === 'active',
                'created_by' => $by?->id,
                'updated_by' => $by?->id,
            ]);
            if (array_key_exists('has_annual_leave', $data)) {
                $employee->has_annual_leave = (bool) $data['has_annual_leave'];
            }
            $changes = $status === 'active' ? $this->makeCurrent($employee, $contract) : [];
            $employee->save();
            $this->log($contract, 'created', $data['comment'] ?? null, $by, $changes);

            return $contract;
        });
    }

    /** @param array{nature_of_contract_id: int, basic_salary: numeric, start_date: string, period: int, date_of_change?: string|null, has_annual_leave?: bool, comment?: string|null} $data */
    public function adjust(EmployeeContract $contract, array $data, ?User $by): EmployeeContract
    {
        $this->assertOpen($contract);

        return DB::transaction(function () use ($contract, $data, $by) {
            $employee = $contract->employee;
            if ($contract->status === 'waiting' && ($active = $employee->activeContract()->first())) {
                $this->assertRenewal($active, CarbonImmutable::parse($data['start_date']), checkWindow: false);
            }

            $contract->update([
                'nature_of_contract_id' => $data['nature_of_contract_id'],
                'basic_salary' => $data['basic_salary'],
                'period' => $data['period'],
                'start_date' => $data['start_date'],
                'end_date' => self::endDate($data['start_date'], (int) $data['period']),
                'date_of_change' => $data['date_of_change'] ?? CarbonImmutable::today(),
                'comment' => $data['comment'] ?? $contract->comment,
                'updated_by' => $by?->id,
            ]);
            if (array_key_exists('has_annual_leave', $data)) {
                $employee->has_annual_leave = (bool) $data['has_annual_leave'];
            }
            $changes = $contract->is_active ? $this->makeCurrent($employee, $contract) : [];
            $employee->save();
            $this->log($contract, 'adjusted', $data['comment'] ?? null, $by, $changes);
            $this->sync($employee);

            return $contract->refresh();
        });
    }

    /**
     * @return array{contract: EmployeeContract, account: string|null} account: `deactivated`, `scheduled`
     *                                                                 (on the leaving date), `kept` (only company admin) or null
     */
    public function end(EmployeeContract $contract, string $reason, string $date, ?string $comment, ?User $by): array
    {
        $this->assertOpen($contract);

        $contract = DB::transaction(function () use ($contract, $reason, $date, $comment, $by) {
            $employee = $contract->employee;
            $this->close($contract, $reason, $date, $comment, $by, 'ended');
            if ((int) $employee->contract_id === $contract->id) {
                $employee->contract_status = $reason;
            }

            if (! in_array($reason, O::CONTRACT_KEEPS_EMPLOYEE, true)) {
                $employee->employment_status = 'terminated';
                $employee->termination_date = $date;
                $employee->contracts()->where('status', 'waiting')->get()
                    ->each(fn (EmployeeContract $waiting) => $this->close($waiting, $reason, $date, 'Cancelled: the employee left.', $by, 'cancelled'));
            }
            $employee->save();
            $this->sync($employee);

            return $contract->refresh();
        });

        $employee = $contract->employee->refresh();
        $account = null;
        if ($employee->employment_status === 'terminated' && $employee->user) {
            $account = match (true) {
                ! $employee->user->is_active => 'deactivated',
                $employee->termination_date->isFuture() => 'scheduled',
                default => 'kept',
            };
        }

        return ['contract' => $contract, 'account' => $account];
    }

    /**
     * Remove a contract given by mistake and give the employee back the contract and number they had before it.
     *
     * @return string What happened, for the user.
     */
    public function revert(EmployeeContract $contract, ?User $by): string
    {
        $employee = $contract->employee;
        if ($employee->contracts()->where('id', '>', $contract->id)->exists()) {
            abort(422, 'Only the latest contract of an employee can be removed.');
        }
        if (DB::table('payroll_sheet_details')->where('contract_id', $contract->id)->exists()) {
            abort(422, 'This contract is already used in payroll, it cannot be removed.');
        }

        $message = DB::transaction(function () use ($contract, $employee, $by) {
            $previous = $employee->contracts()->where('id', '<', $contract->id)->orderByDesc('id')->first();
            $before = $contract->events()->reorder('id')->get()->pluck('changes')->filter()->reduce(
                fn (array $carry, array $c) => $carry + array_map(fn ($pair) => $pair[0], $c), []);
            $contract->events()->delete();
            $contract->forceDelete();
            $message = 'Contract removed.';

            if (array_key_exists('employee_no', $before) && $before['employee_no'] !== $employee->employee_no) {
                $old = $before['employee_no'];
                if (! $this->numbers->taken($old, $employee->id)) {
                    $employee->employee_no = $old;
                    $message = "Contract removed. The employee number is back to {$old}.";
                } elseif ($format = $this->numbers->formatOf($old)) {
                    $employee->employee_no = $this->numbers->nextIn($format, $employee->department_id);
                    $message = "Contract removed. The old number {$old} is now used by another employee, so {$employee->employee_no} was given.";
                } else {
                    $message = "Contract removed. The old number {$old} is now used by another employee, so the number stays {$employee->employee_no}.";
                }
            }
            if (array_key_exists('emp_type', $before) && $before['emp_type']) {
                $employee->emp_type = $before['emp_type'];
            }

            $employee->contract_id = null;
            $employee->contract_status = 'pending';
            if ($previous) {
                if ($previous->status === 'ended' && $previous->end_date->gte(CarbonImmutable::today())) {
                    $previous->update(['status' => 'active', 'is_active' => true, 'updated_by' => $by?->id]);
                    $this->log($previous, 'restored', 'The contract after it was removed.', $by);
                }
                $employee->contract_id = $previous->id;
                $employee->contract_status = $previous->status;
                if ($previous->is_active) {
                    $this->makeCurrent($employee, $previous, renumber: false);
                }
            }
            $employee->save();

            return $message;
        });

        Log::info("Contract {$contract->id} of employee {$employee->id} ({$employee->employee_no}) removed by user ".($by?->username ?? 'system'));

        return $message;
    }

    /**
     * Bring an employee's contracts up to date: end expired ones, start waiting ones whose start date came.
     * Run by `contract:status` and after every change.
     */
    public function sync(Employee $employee): void
    {
        $today = CarbonImmutable::today();

        foreach ($employee->contracts()->where('is_active', true)->whereDate('end_date', '<', $today)->get() as $expired) {
            $this->close($expired, 'ended', $today->toDateString(), null, null, 'auto_ended');
            if ((int) $employee->contract_id === $expired->id) {
                $employee->contract_status = 'ended';
            }
        }

        if ($employee->employment_status !== 'terminated') {
            $due = $employee->contracts()->where('status', 'waiting')->whereDate('start_date', '<=', $today)->orderBy('start_date')->get();
            foreach ($due as $waiting) {
                if ($waiting->end_date->lt($today)) {
                    $this->close($waiting, 'ended', $today->toDateString(), null, null, 'auto_ended');

                    continue;
                }
                $employee->contracts()->where('is_active', true)->where('id', '!=', $waiting->id)->get()
                    ->each(fn (EmployeeContract $old) => $this->close($old, 'ended', $today->toDateString(), 'Replaced by the next contract.', null, 'auto_ended'));
                $waiting->update(['status' => 'active', 'is_active' => true, 'date_of_change' => $today]);
                $changes = $this->makeCurrent($employee, $waiting);
                $employee->save();
                $this->log($waiting, 'activated', null, null, $changes);
            }
        }

        if ($employee->isDirty()) {
            $employee->save();
        }

        if ($employee->employment_status === 'terminated' && $employee->termination_date && ! $employee->termination_date->isFuture() && $employee->user?->is_active) {
            $this->leavers->handle($employee);
        }
    }

    /** @return array<string, array{0: mixed, 1: mixed}> employee fields changed: field => [before, after] */
    private function makeCurrent(Employee $employee, EmployeeContract $contract, bool $renumber = true): array
    {
        $changes = [];
        $employee->contract_id = $contract->id;
        $employee->contract_status = 'active';
        $type = O::empTypeOf(NatureOfContract::withoutCompanyScope()->withTrashed()->whereKey($contract->nature_of_contract_id)->value('employment_type'));
        if ($type && $type !== $employee->emp_type) {
            $changes['emp_type'] = [$employee->emp_type, $type];
            $employee->emp_type = $type;
        }

        if ($renumber && $this->numbers->renumberOnContractChange()) {
            $format = $this->numbers->forContractType($contract->nature_of_contract_id);
            if ($format && ! $this->numbers->matches($format, $employee->employee_no)) {
                $number = $this->numbers->nextIn($format, $employee->department_id);
                $changes['employee_no'] = [$employee->employee_no, $number];
                $employee->employee_no = $number;
            }
        }

        if ($contract->contract_no !== $employee->employee_no) {
            $contract->update(['contract_no' => $employee->employee_no]);
        }

        return $changes;
    }

    private function close(EmployeeContract $contract, string $status, string $date, ?string $comment, ?User $by, string $action): void
    {
        $contract->update([
            'is_active' => false,
            'status' => $status,
            'date_of_change' => $date,
            'comment' => $comment ?? $contract->comment,
            'updated_by' => $by?->id ?? $contract->updated_by,
        ]);
        $this->log($contract, $action, $comment, $by);
    }

    private function log(EmployeeContract $contract, string $action, ?string $comment, ?User $by, array $changes = []): void
    {
        $contract->events()->create([
            'action' => $action, 'status' => $contract->status, 'comment' => $comment, 'user_id' => $by?->id, 'changes' => $changes ?: null,
        ]);
    }

    private function assertOpen(EmployeeContract $contract): void
    {
        if (! in_array($contract->status, ['active', 'waiting'], true)) {
            abort(422, 'Only an active or waiting contract can be changed.');
        }
    }

    private function assertRenewal(EmployeeContract $active, CarbonImmutable $start, bool $checkWindow = true): void
    {
        $ends = $active->end_date->format('d M Y');
        if ($checkWindow && CarbonImmutable::today()->diffInDays($active->end_date, false) > O::RENEWAL_WINDOW_DAYS) {
            throw ValidationException::withMessages(['start_date' => "The current contract runs until {$ends}. A renewal can be added in its last ".O::RENEWAL_WINDOW_DAYS.' days.']);
        }
        if ($start->lte($active->end_date)) {
            throw ValidationException::withMessages(['start_date' => "The renewal must start after the current contract ends ({$ends})."]);
        }
    }
}
