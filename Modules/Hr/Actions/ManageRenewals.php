<?php

namespace Modules\Hr\Actions;

use App\Models\ContractRenewal;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Hr\Support\EmployeeFiles;
use Modules\Hr\Support\EmployeeOptions as O;
use Modules\Hr\Workflow\ContractRenewalProcess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * Contract renewal, two ways: HR starts it for an employee, or the employee requests it. Both go through the
 * "contract-renewal" approval workflow and exclude each other: an employee has at most one open renewal.
 * A renewal opens in the last 90 days of the active contract (as HRMS renewals). When approved with complete terms
 * the waiting contract is created at once; otherwise HR completes the terms.
 */
class ManageRenewals
{
    public function __construct(
        private readonly ManageContracts $contracts,
        private readonly WorkflowEngine $workflow,
    ) {}

    /** Why a renewal cannot be started now, or null. `$source` words the open-renewal message for who is asking. */
    public function blocker(Employee $employee, string $source): ?string
    {
        if ($employee->employment_status === 'terminated') {
            return 'This employee has left the organization.';
        }
        $open = $employee->renewals()->whereIn('status', ContractRenewal::OPEN)->first();
        if ($open) {
            return match (true) {
                $open->source === ContractRenewal::FROM_EMPLOYEE && $source === ContractRenewal::FROM_HR => 'The employee already requested renewal on '.$open->created_at->format('d M Y').'. Handle that request instead.',
                $open->source === ContractRenewal::FROM_HR && $source === ContractRenewal::FROM_EMPLOYEE => 'HR has already started your contract renewal.',
                default => 'A renewal is already in progress.',
            };
        }
        if ($employee->waitingContract()->exists()) {
            return 'The next contract is already in place.';
        }
        $active = $employee->activeContract()->first();
        if (! $active) {
            return 'There is no active contract to renew.';
        }
        $opens = $active->end_date->toImmutable()->subDays(O::RENEWAL_WINDOW_DAYS);
        if ($opens->gt(CarbonImmutable::today())) {
            return 'Renewal opens '.O::RENEWAL_WINDOW_DAYS.' days before the contract ends, on '.$opens->format('d M Y').'.';
        }

        return null;
    }

    /**
     * @param  array{comment?: string|null, letter?: string|null, agreed?: bool, nature_of_contract_id?: int|null, period?: int|null, basic_salary?: numeric|null}  $data
     * @param  list<UploadedFile>  $files
     */
    public function start(Employee $employee, string $source, array $data, User $by, array $files = []): ContractRenewal
    {
        if ($reason = $this->blocker($employee, $source)) {
            throw ValidationException::withMessages(['renewal' => $reason]);
        }

        $renewal = DB::transaction(function () use ($employee, $source, $data, $by, $files) {
            $renewal = $employee->renewals()->create([
                'contract_id' => $employee->activeContract()->value('id'),
                'source' => $source,
                'status' => 'pending',
                'comment' => $data['comment'] ?? null,
                'letter' => $data['letter'] ?? null,
                'agreement' => ! empty($data['agreed']) ? ContractRenewal::AGREEMENT : null,
                'agreed_at' => ! empty($data['agreed']) ? now() : null,
                'nature_of_contract_id' => $data['nature_of_contract_id'] ?? null,
                'period' => $data['period'] ?? null,
                'basic_salary' => $data['basic_salary'] ?? null,
                'requested_by' => $by->id,
            ]);
            foreach ($files as $file) {
                $renewal->files()->create([
                    'file_path' => EmployeeFiles::store($file, $employee, 'renewals'),
                    'file_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                ]);
            }

            return $renewal;
        });

        if (! $this->workflow->start(ContractRenewalProcess::KEY, $renewal, $by)) {
            $this->approve($renewal->refresh());
        }

        return $renewal->refresh();
    }

    /** Approved: create the waiting contract when the terms are complete, otherwise leave it for HR. */
    public function approve(ContractRenewal $renewal): void
    {
        $renewal->update(['status' => 'approved', 'decided_at' => now()]);
        if ($renewal->termsComplete()) {
            try {
                $this->complete($renewal, [], auth()->user());
            } catch (ValidationException) {
                // HR completes it from the employee page.
            }
        }
    }

    public function reject(ContractRenewal $renewal, ?string $comment): void
    {
        $withdrawn = $comment === 'Withdrawn by the requester.';
        $renewal->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
    }

    /**
     * Create the renewal contract (waiting, starting the day after the current one ends).
     *
     * @param  array{nature_of_contract_id?: int, period?: int, basic_salary?: numeric, has_annual_leave?: bool, comment?: string|null}  $terms
     */
    public function complete(ContractRenewal $renewal, array $terms, ?User $by): ContractRenewal
    {
        if ($renewal->status !== 'approved') {
            abort(422, 'Only an approved renewal can be turned into a contract.');
        }
        $employee = $renewal->employee;
        $current = $employee->activeContract()->first();
        $start = $current ? $current->end_date->toImmutable()->addDay() : CarbonImmutable::today();

        $data = array_filter([
            'nature_of_contract_id' => $terms['nature_of_contract_id'] ?? $renewal->nature_of_contract_id,
            'period' => $terms['period'] ?? $renewal->period,
            'basic_salary' => $terms['basic_salary'] ?? $renewal->basic_salary,
        ], fn ($v) => $v !== null);
        if (count($data) < 3) {
            throw ValidationException::withMessages(['renewal' => 'Choose the contract type, period and basic salary.']);
        }

        return DB::transaction(function () use ($renewal, $employee, $data, $terms, $start, $by) {
            $contract = $this->contracts->give($employee, $data + [
                'start_date' => $start->toDateString(),
                'comment' => $terms['comment'] ?? 'Renewal '.($renewal->source === ContractRenewal::FROM_EMPLOYEE ? 'requested by the employee' : 'started by HR'),
            ] + (array_key_exists('has_annual_leave', $terms) ? ['has_annual_leave' => $terms['has_annual_leave']] : []), $by);
            $renewal->update(['status' => 'completed', 'new_contract_id' => $contract->id] + $data);

            return $renewal;
        });
    }

    /** Withdraw a pending renewal (only the person who started it). */
    public function withdraw(ContractRenewal $renewal, User $by): void
    {
        if ($renewal->status !== 'pending') {
            abort(422, 'Only a renewal waiting for approval can be withdrawn.');
        }
        if ($instance = $renewal->workflow) {
            $this->workflow->cancel($instance, $by);

            return;
        }
        abort_unless($renewal->requested_by === $by->id, 403, 'Only the person who started it can withdraw it.');
        $renewal->update(['status' => 'cancelled', 'decided_at' => now()]);
    }
}
