<?php

namespace Modules\Hr\Workflow;

use App\Models\EmployeeMonthlyAdvance;
use Illuminate\Database\Eloquent\Model;
use Modules\Hr\Actions\ManageLoans;
use Modules\Workflow\Contracts\WorkflowProcess;

class SalaryAdvanceProcess implements WorkflowProcess
{
    public const KEY = 'salary-advance';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'loans';
    }

    public function name(): string
    {
        return 'Salary advance';
    }

    public function description(): string
    {
        return 'An advance on salary, deducted in full on the next payroll. Without this workflow, advances apply when entered.';
    }

    /** @param EmployeeMonthlyAdvance $subject */
    public function summary(Model $subject): array
    {
        $basic = (float) ($subject->employee?->activeContract()->value('basic_salary') ?? 0);

        return [
            'title' => 'Salary advance for '.($subject->employee?->full_name ?? 'employee'),
            'lines' => array_values(array_filter([
                'Amount: '.number_format($subject->amount, 2).($basic > 0 ? ' ('.round($subject->amount / $basic * 100).'% of basic salary)' : ''),
                'Date: '.$subject->date_advanced->format('d M Y'),
                $subject->reason ? 'Reason: '.$subject->reason : null,
                $subject->source === 'employee' ? 'Requested by the employee' : 'Entered by HR',
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
        app(ManageLoans::class)->approveAdvance($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageLoans::class)->rejectAdvance($subject, $comment);
    }
}
