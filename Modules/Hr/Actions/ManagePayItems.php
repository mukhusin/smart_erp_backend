<?php

namespace Modules\Hr\Actions;

use App\Models\AllowanceCategory;
use App\Models\EmpAllowance;
use App\Models\Employee;
use App\Models\OtherDeduction;
use App\Models\SalaryAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Hr\Workflow\AssignedAllowanceProcess;
use Modules\Hr\Workflow\OtherDeductionProcess;
use Modules\Hr\Workflow\SalaryAdjustmentProcess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * Pay items set per employee: assigned monthly allowances, other monthly deductions and salary adjustments.
 * Each goes through its approval workflow (or applies at once when none is set up).
 */
class ManagePayItems
{
    public const WITHDRAWN = 'Withdrawn by the requester.';

    public function __construct(private readonly WorkflowEngine $workflow) {}

    /** @param array{allowance_category_id: int, amount: numeric, start_date: string, end_date?: string|null, description?: string|null} $d */
    public function assignAllowance(Employee $employee, array $d, User $by): EmpAllowance
    {
        $category = AllowanceCategory::where('is_active', true)->findOrFail($d['allowance_category_id']);
        if ($category->generation !== 'assigned') {
            throw ValidationException::withMessages(['allowance_category_id' => "{$category->category} is not assigned per employee. Choose a monthly category set to \"assigned\"."]);
        }
        if (! app(ManageAllowances::class)->eligible($employee)) {
            throw ValidationException::withMessages(['employee_id' => "{$employee->full_name}'s contract does not give allowances."]);
        }
        $overlap = EmpAllowance::where('employee_id', $employee->id)->where('allowance_category_id', $category->id)->whereIn('status', ['pending', 'approved'])
            ->whereDate('start_date', '<=', $d['end_date'] ?? '9999-12-31')->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $d['start_date']))->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['allowance_category_id' => "{$employee->full_name} already has {$category->category} for these dates."]);
        }
        $row = EmpAllowance::create([
            'employee_id' => $employee->id, 'allowance_category_id' => $category->id, 'category' => $category->category, 'duration' => 'month', 'items' => 1,
            'amount' => $d['amount'], 'amount_per_item' => $d['amount'], 'start_date' => $d['start_date'], 'end_date' => $d['end_date'] ?? null,
            'description' => $d['description'] ?? null, 'status' => 'pending', 'is_active' => false, 'requested_by' => $by->id,
        ]);

        return $this->start(AssignedAllowanceProcess::KEY, $row, $by);
    }

    /** @param array{name: string, amount: numeric, start_month: string, months?: int|null, description?: string|null} $d */
    public function addDeduction(Employee $employee, array $d, User $by): OtherDeduction
    {
        $row = OtherDeduction::create([
            'employee_id' => $employee->id, 'name' => trim($d['name']), 'amount' => $d['amount'], 'start_month' => Carbon::parse($d['start_month'].'-01')->toDateString(),
            'months' => $d['months'] ?? null, 'description' => $d['description'] ?? null, 'status' => 'pending', 'requested_by' => $by->id,
        ]);

        return $this->start(OtherDeductionProcess::KEY, $row, $by);
    }

    /** @param array{new_salary: numeric, reason: string, effective_date?: string|null, description?: string|null} $d */
    public function adjustSalary(Employee $employee, array $d, User $by): SalaryAdjustment
    {
        $contract = $employee->activeContract()->first();
        if (! $contract) {
            throw ValidationException::withMessages(['employee_id' => "{$employee->full_name} has no active contract."]);
        }
        if (SalaryAdjustment::where('employee_id', $employee->id)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['employee_id' => "{$employee->full_name} already has a salary adjustment waiting for approval."]);
        }
        $current = (float) $contract->basic_salary;
        $new = round((float) $d['new_salary'], 2);
        if ($new === $current) {
            throw ValidationException::withMessages(['new_salary' => 'The new salary is the same as the current one.']);
        }
        $row = SalaryAdjustment::create([
            'employee_id' => $employee->id, 'contract_id' => $contract->id, 'adjustment_type' => $new > $current ? 'increase' : 'decrease',
            'current_salary' => $current, 'new_salary' => $new, 'amount' => abs($new - $current), 'percentage' => $current > 0 ? round(abs($new - $current) / $current * 100, 2) : null,
            'reason' => $d['reason'], 'effective_date' => $d['effective_date'] ?? now()->toDateString(), 'description' => $d['description'] ?? null,
            'status' => 'pending', 'is_active' => true, 'requested_by' => $by->id,
        ]);

        return $this->start(SalaryAdjustmentProcess::KEY, $row, $by);
    }

    public function approve(Model $item): void
    {
        $employee = $item->employee;
        match (true) {
            $item instanceof EmpAllowance => $item->update(['status' => 'approved', 'is_active' => true]),
            $item instanceof OtherDeduction => $item->update(['status' => 'approved']),
            $item instanceof SalaryAdjustment => $item->update(['status' => 'approved']),
        };
        if ($item instanceof SalaryAdjustment && ! $item->effective_date?->isFuture()) {
            $this->applyAdjustment($item);
        }
        $employee?->user?->notify(new Notice(...$this->approvedNotice($item)));
    }

    public function reject(Model $item, ?string $comment): void
    {
        $withdrawn = $comment === self::WITHDRAWN;
        $status = $withdrawn ? 'cancelled' : 'rejected';
        $item instanceof SalaryAdjustment
            ? $item->update(['status' => $status, 'director_comment' => $withdrawn ? null : $comment])
            : $item->update(['status' => $status, 'decision_comment' => $withdrawn ? null : $comment]);
    }

    /** Applies an approved salary adjustment to the active contract (on approval, or on its effective date). */
    public function applyAdjustment(SalaryAdjustment $adj): void
    {
        if ($adj->applied_at || $adj->status !== 'approved') {
            return;
        }
        $contract = $adj->employee?->activeContract()->first();
        if (! $contract) {
            return;
        }
        $contract->update(['basic_salary' => $adj->new_salary]);
        $adj->update(['applied_at' => now(), 'contract_id' => $contract->id]);
    }

    /** @return int adjustments applied */
    public function applyDue(): int
    {
        $due = SalaryAdjustment::with('employee')->where('status', 'approved')->whereNull('applied_at')->whereDate('effective_date', '<=', now())->get();
        $due->each(fn ($a) => $this->applyAdjustment($a));

        return $due->count();
    }

    /** Ends an approved allowance or deduction from a date or month; the payrolls after that leave it out. */
    public function stop(EmpAllowance|OtherDeduction $item, string $date): void
    {
        if ($item->status !== 'approved') {
            throw ValidationException::withMessages(['status' => 'Only approved items can be stopped.']);
        }
        $item instanceof EmpAllowance
            ? $item->update(['end_date' => Carbon::parse($date)->max($item->start_date)->toDateString()])
            : $item->update(['stopped_at' => Carbon::parse($date)->max($item->start_month)->toDateString()]);
    }

    public function delete(Model $item, User $by): void
    {
        if (! in_array($item->status, ['pending', 'rejected', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => $item instanceof SalaryAdjustment ? 'An approved adjustment cannot be removed; make a new adjustment instead.' : 'An approved item may have been paid; stop it instead.']);
        }
        if ($instance = $item->workflow()->where('status', 'pending')->first()) {
            $this->workflow->cancel($instance, $by);
        }
        $item->delete();
    }

    private function start(string $process, Model $item, User $by): Model
    {
        if (! $this->workflow->start($process, $item, $by)) {
            $this->approve($item);
        }

        return $item->refresh();
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} */
    private function approvedNotice(Model $item): array
    {
        $money = fn ($v) => number_format((float) $v, 2);

        return match (true) {
            $item instanceof EmpAllowance => ['Allowance assigned', "{$item->category}: {$money($item->amount)} a month from {$item->start_date->format('d M Y')}.", '/self-service/payslips', 'cash-coin'],
            $item instanceof OtherDeduction => ['Deduction from salary', "{$item->name}: {$money($item->amount)} a month from {$item->start_month->format('F Y')}".($item->months ? " for {$item->months} months." : '.'), '/self-service/payslips', 'dash-circle'],
            default => ['Salary adjusted', "Your basic salary changes from {$money($item->current_salary)} to {$money($item->new_salary)}".($item->effective_date ? " from {$item->effective_date->format('d M Y')}." : '.'), '/self-service/contract', 'graph-up-arrow'],
        };
    }
}
