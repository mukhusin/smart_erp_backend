<?php

namespace Modules\Hr\Workflow;

use App\Models\SalaryAdjustment;
use Illuminate\Database\Eloquent\Model;
use Modules\Hr\Actions\ManagePayItems;
use Modules\Workflow\Contracts\WorkflowProcess;

class SalaryAdjustmentProcess implements WorkflowProcess
{
    public const KEY = 'salary-adjustment';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'payroll';
    }

    public function name(): string
    {
        return 'Salary adjustment';
    }

    public function description(): string
    {
        return 'A change of basic salary. Once approved, the active contract gets the new salary on the effective date. Without this workflow, it applies when entered.';
    }

    public function summary(Model $subject): array
    {
        $m = fn ($v) => number_format((float) $v, 2);
        $name = $subject->employee?->full_name ?? 'employee';

        return [
            'title' => 'Salary '.($subject->adjustment_type === 'increase' ? 'increase' : 'decrease')." for {$name}",
            'lines' => array_values(array_filter([
                'Basic salary: '.$m($subject->current_salary).' → '.$m($subject->new_salary).($subject->percentage ? ' ('.($subject->adjustment_type === 'increase' ? '+' : '−').$subject->percentage.'%)' : ''),
                'Reason: '.(SalaryAdjustment::REASONS[$subject->reason] ?? $subject->reason).($subject->effective_date ? ' · from '.$subject->effective_date->format('d M Y') : ''),
                $subject->description ? 'Note: '.$subject->description : null,
            ])),
            'link' => null,
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return $subject->employee?->company_branch_id;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->employee?->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return $subject->employee_id;
    }

    public function onApproved(Model $subject): void
    {
        app(ManagePayItems::class)->approve($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManagePayItems::class)->reject($subject, $comment);
    }
}
