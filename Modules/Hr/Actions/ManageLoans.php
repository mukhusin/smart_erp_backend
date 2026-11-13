<?php

namespace Modules\Hr\Actions;

use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\EmployeeMonthlyAdvance;
use App\Models\PayrollDateSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Hr\Workflow\LoanProcess;
use Modules\Hr\Workflow\SalaryAdvanceProcess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * Employee loans and salary advances (HRMS loans), deducted by payroll.
 *
 * A loan has a provider, an amount and a monthly instalment; only one open loan per provider and employee (as HRMS).
 * Payroll takes the instalment (or what remains) each month until it is repaid; HR can pause and resume it.
 * A salary advance is deducted in full on the next payroll. Both go through their approval workflow when the company
 * set one up ("Employee loan", "Salary advance"); without one they apply at once. Employees can ask for an advance
 * of up to half their basic salary, one at a time.
 */
class ManageLoans
{
    public const ADVANCE_MAX_PERCENT = 50;

    public function __construct(private readonly WorkflowEngine $workflow) {}

    /** @param array{loan_provider_id: int, amount: numeric, instalment: numeric, date_of_loan: string, description?: string|null} $data */
    public function createLoan(Employee $employee, array $data, User $by): EmployeeLoan
    {
        $this->checkLoan($employee, $data);
        $loan = EmployeeLoan::create([
            'employee_id' => $employee->id, 'loan_provider_id' => $data['loan_provider_id'], 'amount' => $data['amount'], 'instalment' => $data['instalment'],
            'returned' => 0, 'remain' => $data['amount'], 'date_of_loan' => $data['date_of_loan'], 'description' => $data['description'] ?? null,
            'status' => 'pending', 'is_active' => false, 'requested_by' => $by->id, 'updated_by' => $by->employee?->id,
        ]);
        if (! $this->workflow->start(LoanProcess::KEY, $loan, $by)) {
            $this->approveLoan($loan);
        }

        return $loan->refresh();
    }

    /** @param array{loan_provider_id: int, amount: numeric, instalment: numeric, date_of_loan: string, description?: string|null} $data */
    public function updateLoan(EmployeeLoan $loan, array $data, User $by): EmployeeLoan
    {
        if (in_array($loan->status, ['rejected', 'cancelled', 'disabled'], true)) {
            throw ValidationException::withMessages(['loan' => 'This loan is closed and cannot change.']);
        }
        if ((float) $data['amount'] < $loan->returned) {
            throw ValidationException::withMessages(['amount' => 'The amount cannot be less than what has been repaid ('.number_format($loan->returned, 2).').']);
        }
        $this->checkLoan($loan->employee, $data, $loan);
        $remain = round((float) $data['amount'] - $loan->returned, 2);
        $loan->update([
            'loan_provider_id' => $data['loan_provider_id'], 'amount' => $data['amount'], 'instalment' => $data['instalment'], 'remain' => $remain,
            'date_of_loan' => $data['date_of_loan'], 'description' => $data['description'] ?? null, 'updated_by' => $by->employee?->id,
        ] + ($remain <= 0 && $loan->status !== 'pending' ? ['status' => 'disabled', 'is_active' => false, 'end_date' => now()->toDateString()] : []));

        return $loan->refresh();
    }

    private function checkLoan(Employee $employee, array $data, ?EmployeeLoan $except = null): void
    {
        if ((float) $data['instalment'] > (float) $data['amount']) {
            throw ValidationException::withMessages(['instalment' => 'The instalment cannot be more than the loan.']);
        }
        $open = EmployeeLoan::with('loanProvider')->where('employee_id', $employee->id)->where('loan_provider_id', $data['loan_provider_id'])
            ->whereIn('status', ['pending', 'enabled', 'paused'])->where('remain', '>', 0)->when($except, fn ($q) => $q->whereKeyNot($except->id))->first();
        if ($open) {
            throw ValidationException::withMessages(['loan_provider_id' => "{$employee->full_name} already has an open loan from {$open->loanProvider?->name} (".number_format($open->remain, 2).' left).']);
        }
    }

    public function approveLoan(EmployeeLoan $loan): void
    {
        $loan->update(['status' => 'enabled', 'is_active' => true, 'decided_at' => now()]);
        $loan->employee?->user?->notify(new Notice('Loan recorded', sprintf('Your %s loan of %s is recorded: %s a month from your salary.',
            $loan->loanProvider?->name ?? '', number_format($loan->amount, 2), number_format($loan->instalment, 2)), '/self-service/loans', 'bank'));
    }

    public function rejectLoan(EmployeeLoan $loan, ?string $comment): void
    {
        $withdrawn = $comment === 'Withdrawn by the requester.';
        $loan->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
    }

    public function pause(EmployeeLoan $loan, bool $pause): EmployeeLoan
    {
        if (! in_array($loan->status, ['enabled', 'paused'], true)) {
            throw ValidationException::withMessages(['loan' => 'Only a loan being repaid can be paused or resumed.']);
        }
        $loan->update(['status' => $pause ? 'paused' : 'enabled', 'is_active' => ! $pause]);

        return $loan->refresh();
    }

    public function delete(EmployeeLoan $loan): void
    {
        if ($loan->histories()->where('status', 'applied')->exists()) {
            throw ValidationException::withMessages(['loan' => 'Repayments have been made; pause the loan instead.']);
        }
        if ($instance = $loan->workflow()->where('status', 'pending')->first()) {
            $this->workflow->cancel($instance, auth()->user());
        }
        $loan->histories()->delete();
        $loan->delete();
    }

    /** Upcoming instalments, month by month from the next payroll. @return list<array{month: string, instalment: float, remaining: float}> */
    public function schedule(EmployeeLoan $loan): array
    {
        $out = [];
        $pending = (float) $loan->histories()->where('status', 'pending')->sum('instalment');
        $remain = $loan->remain - $pending;
        if ($loan->instalment <= 0 || in_array($loan->status, ['rejected', 'cancelled', 'disabled'], true)) {
            return $out;
        }
        $month = now()->startOfMonth();
        [, $to] = PayrollDateSetting::current()->period($month->year, $month->month);
        if ($to->lt(now()->startOfDay()) || $pending > 0) {
            $month->addMonth();
        }
        while ($remain > 0.009 && count($out) < 120) {
            $pay = round(min($loan->instalment, $remain), 2);
            $remain = round($remain - $pay, 2);
            $out[] = ['month' => $month->format('Y-m'), 'instalment' => $pay, 'remaining' => max(0, $remain)];
            $month->addMonth();
        }

        return $out;
    }

    /** @param array{amount: numeric, date_advanced?: string|null, reason?: string|null} $data */
    public function createAdvance(Employee $employee, array $data, User $by, string $source = 'hr'): EmployeeMonthlyAdvance
    {
        $amount = (float) $data['amount'];
        if ($source === 'employee') {
            $basic = (float) ($employee->activeContract()->value('basic_salary') ?? 0);
            if ($basic <= 0) {
                throw ValidationException::withMessages(['amount' => 'You need an active contract to ask for a salary advance.']);
            }
            if ($amount > $basic * self::ADVANCE_MAX_PERCENT / 100) {
                throw ValidationException::withMessages(['amount' => 'You can ask for up to '.self::ADVANCE_MAX_PERCENT.'% of your basic salary ('.number_format($basic * self::ADVANCE_MAX_PERCENT / 100, 2).').']);
            }
            if (EmployeeMonthlyAdvance::where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])->exists()) {
                throw ValidationException::withMessages(['amount' => 'You already have a salary advance waiting to be deducted.']);
            }
        }
        $date = Carbon::parse($data['date_advanced'] ?? now())->startOfDay();
        $advance = EmployeeMonthlyAdvance::create([
            'employee_id' => $employee->id, 'amount' => $amount, 'reason' => $data['reason'] ?? null, 'date_advanced' => $date, 'month' => (string) $date->month,
            'year' => (string) $date->year, 'status' => 'pending', 'is_active' => true, 'source' => $source, 'requested_by' => $by->id, 'created_by' => $by->employee?->id,
        ]);
        if (! $this->workflow->start(SalaryAdvanceProcess::KEY, $advance, $by)) {
            $this->approveAdvance($advance);
        }

        return $advance->refresh();
    }

    public function approveAdvance(EmployeeMonthlyAdvance $advance): void
    {
        $advance->update(['status' => 'approved', 'decided_at' => now()]);
        $advance->employee?->user?->notify(new Notice('Salary advance approved', 'Your salary advance of '.number_format($advance->amount, 2).' is approved; it is deducted on the next payroll.', '/self-service/loans', 'cash'));
    }

    public function rejectAdvance(EmployeeMonthlyAdvance $advance, ?string $comment): void
    {
        $withdrawn = $comment === 'Withdrawn by the requester.';
        $advance->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
        if (! $withdrawn) {
            $advance->employee?->user?->notify(new Notice('Salary advance not approved', 'Your salary advance was not approved.'.($comment ? " Reason: {$comment}" : ''), '/self-service/loans', 'cash'));
        }
    }

    /** Cancel an advance not yet on an approved payroll. */
    public function cancelAdvance(EmployeeMonthlyAdvance $advance, User $by): EmployeeMonthlyAdvance
    {
        if (! in_array($advance->status, ['pending', 'approved'], true) || $advance->payroll_sheet_detail_id) {
            throw ValidationException::withMessages(['advance' => 'This advance is on a payroll or already closed.']);
        }
        if ($advance->status === 'pending' && ($instance = $advance->workflow()->where('status', 'pending')->first())) {
            $this->workflow->cancel($instance, $by);
        } else {
            $advance->update(['status' => 'cancelled', 'decided_at' => now()]);
        }

        return $advance->refresh();
    }
}
