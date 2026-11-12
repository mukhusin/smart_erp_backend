<?php

namespace Modules\Hr\Workflow;

use App\Models\PayrollSheet;
use Illuminate\Database\Eloquent\Model;
use Modules\Hr\Actions\ManagePayroll;
use Modules\Workflow\Contracts\WorkflowProcess;

class PayrollSheetProcess implements WorkflowProcess
{
    public const KEY = 'payroll-sheet';

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
        return 'Payroll sheet';
    }

    public function description(): string
    {
        return 'Approving a month\'s payroll. HRMS: payroll officer, director, then finance. Without this workflow, payroll is approved when submitted.';
    }

    /** @param PayrollSheet $subject */
    public function summary(Model $subject): array
    {
        $m = fn ($v) => number_format((float) $v, 2);

        return [
            'title' => $subject->name,
            'lines' => [
                'Period: '.$subject->payroll_from_date->format('d M Y').' to '.$subject->payroll_to_date->format('d M Y').' ('.$subject->number_of_days.' days)',
                "Employees: {$subject->employees_count}",
                'Gross pay: '.$m($subject->gross_total).' · Deductions: '.$m($subject->deduction_total).' · Net pay: '.$m($subject->net_total),
                'Employer costs (NSSF, NHIF, WCF, SDL): '.$m($subject->employer_total).' · Total cost: '.$m($subject->payroll_cost),
            ],
            'link' => "/payroll/sheets/{$subject->id}",
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return null;
    }

    public function departmentId(Model $subject): ?int
    {
        return null;
    }

    public function employeeId(Model $subject): ?int
    {
        return null;
    }

    public function onApproved(Model $subject): void
    {
        app(ManagePayroll::class)->approve($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManagePayroll::class)->reject($subject, $comment);
    }
}
