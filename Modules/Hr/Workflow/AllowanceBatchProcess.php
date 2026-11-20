<?php

namespace Modules\Hr\Workflow;

use App\Models\AllowanceBatch;
use Illuminate\Database\Eloquent\Model;
use Modules\Hr\Actions\ManageAllowanceBatches;
use Modules\Workflow\Contracts\WorkflowProcess;

class AllowanceBatchProcess implements WorkflowProcess
{
    public const KEY = 'allowance-batch';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'allowance';
    }

    public function name(): string
    {
        return 'Daily allowance batch';
    }

    public function description(): string
    {
        return "A team's counts for one day (e.g. theatre cases per employee), sent by the head of the section as one batch.";
    }

    /** @param AllowanceBatch $subject */
    public function summary(Model $subject): array
    {
        $subject->loadMissing('category', 'department', 'creator', 'lines');

        return [
            'title' => $subject->category->category.' · '.$subject->department->name.' · '.$subject->date->format('d M Y'),
            'lines' => array_values(array_filter([
                $subject->lines->count().' employees, '.rtrim(rtrim(number_format($subject->lines->sum('items'), 2, '.', ''), '0'), '.').' items, total '.number_format($subject->lines->sum('amount'), 2),
                $subject->notes ? 'Note: '.$subject->notes : null,
                $subject->creator ? 'Entered by '.$subject->creator->display_name : null,
            ])),
            'link' => '/allowance/batches/'.$subject->id,
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return $subject->department?->company_branch_id ?? $subject->creator?->employee?->company_branch_id;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->department?->parent_id ?: $subject->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return $subject->creator?->employee?->id;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageAllowanceBatches::class)->approve($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageAllowanceBatches::class)->reject($subject, $comment);
    }
}
