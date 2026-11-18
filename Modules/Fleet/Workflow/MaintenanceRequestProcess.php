<?php

namespace Modules\Fleet\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Fleet\Actions\ManageMaintenance;
use Modules\Workflow\Contracts\WorkflowProcess;

class MaintenanceRequestProcess implements WorkflowProcess
{
    public const KEY = 'maintenance-request';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'fleet';
    }

    public function name(): string
    {
        return 'Repair request';
    }

    public function description(): string
    {
        return 'A fault reported on a vehicle or generator. Once approved the transport office inspects it and prices the repair.';
    }

    public function summary(Model $subject): array
    {
        $subject->loadMissing(['unit', 'requester']);

        return [
            'title' => "{$subject->number} repair {$subject->unit?->label()}",
            'lines' => array_values(array_filter([$subject->description, 'Asked by '.$subject->requester?->display_name])),
            'link' => "/fleet/maintenance/{$subject->id}",
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return $subject->unit?->company_branch_id;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->unit?->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return null;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageMaintenance::class)->approved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageMaintenance::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }
}
