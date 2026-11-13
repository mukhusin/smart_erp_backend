<?php

namespace Modules\Hr\Workflow;

use App\Models\EmployeeLoan;
use Illuminate\Database\Eloquent\Model;
use Modules\Hr\Actions\ManageLoans;
use Modules\Workflow\Contracts\WorkflowProcess;

class LoanProcess implements WorkflowProcess
{
    public const KEY = 'employee-loan';

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
        return 'Employee loan';
    }

    public function description(): string
    {
        return 'A loan repaid from salary (from a bank, SACCOS or the company). Without this workflow, loans apply when entered.';
    }

    /** @param EmployeeLoan $subject */
    public function summary(Model $subject): array
    {
        $months = $subject->instalment > 0 ? (int) ceil($subject->amount / $subject->instalment) : null;

        return [
            'title' => ($subject->loanProvider?->name ?? 'Loan').' loan for '.($subject->employee?->full_name ?? 'employee'),
            'lines' => array_values(array_filter([
                'Amount: '.number_format($subject->amount, 2).' · Instalment: '.number_format($subject->instalment, 2).' a month'.($months ? " (about {$months} months)" : ''),
                'From '.$subject->date_of_loan->format('d M Y'),
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
        app(ManageLoans::class)->approveLoan($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageLoans::class)->rejectLoan($subject, $comment);
    }
}
