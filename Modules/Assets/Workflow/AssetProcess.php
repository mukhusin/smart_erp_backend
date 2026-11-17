<?php

namespace Modules\Assets\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

abstract class AssetProcess implements HasAmount, WorkflowProcess
{
    public const KEY = '';

    abstract protected function handler(): object;

    abstract protected function asset(Model $subject): \Modules\Assets\Models\Asset;

    public function key(): string
    {
        return static::KEY;
    }

    public function module(): string
    {
        return 'assets';
    }

    public function branchId(Model $subject): ?int
    {
        return $this->asset($subject)->company_branch_id;
    }

    public function departmentId(Model $subject): ?int
    {
        return $this->asset($subject)->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return null;
    }

    public function amount(Model $subject): ?float
    {
        return $this->asset($subject)->cost;
    }

    public function onApproved(Model $subject): void
    {
        $this->handler()->approved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        $this->handler()->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }
}
