<?php

namespace Modules\Hr\Workflow;

use App\Models\ContractRenewal;
use Illuminate\Database\Eloquent\Model;
use Modules\Hr\Actions\ManageRenewals;
use Modules\Workflow\Contracts\WorkflowProcess;

class ContractRenewalProcess implements WorkflowProcess
{
    public const KEY = 'contract-renewal';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'hr';
    }

    public function name(): string
    {
        return 'Contract renewal';
    }

    public function description(): string
    {
        return 'Renewing an employee\'s contract, started by HR or requested by the employee.';
    }

    /** @param ContractRenewal $subject */
    public function summary(Model $subject): array
    {
        $employee = $subject->employee;
        $current = $subject->contract;
        $previous = $employee?->contracts()->where('id', '!=', $subject->contract_id)->count() ?? 0;
        $money = fn ($v) => number_format((float) $v, 2);

        $lines = array_values(array_filter([
            $subject->source === ContractRenewal::FROM_EMPLOYEE ? 'Requested by the employee' : 'Started by HR',
            $current ? 'Current: '.($current->natureOfContract?->contract_name ?? 'contract').', ends '.$current->end_date->format('d M Y')
                .' ('.max(0, (int) now()->startOfDay()->diffInDays($current->end_date, false)).' days left)' : null,
            $subject->nature_of_contract_id || $subject->period || $subject->basic_salary !== null ? 'Proposed: '.implode(', ', array_filter([
                $subject->natureOfContract?->contract_name,
                $subject->period ? $subject->period.' months' : null,
                $subject->basic_salary !== null ? 'basic salary '.$money($subject->basic_salary) : null,
            ])) : null,
            $subject->letter ? 'Request letter: '.$subject->letter : null,
            $subject->agreed_at ? 'Agreement confirmed on '.$subject->agreed_at->format('d M Y H:i') : null,
            $subject->comment ? 'Comment: '.$subject->comment : null,
            $subject->files()->count() ? 'Attachments: '.$subject->files()->count() : null,
            $previous ? "Previous contracts: {$previous}" : null,
        ]));

        return [
            'title' => 'Renew contract of '.($employee?->full_name ?? 'employee'),
            'lines' => $lines,
            'link' => $employee ? "/hr/employees/{$employee->id}?tab=contracts" : null,
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
        app(ManageRenewals::class)->approve($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageRenewals::class)->reject($subject, $comment);
    }
}
