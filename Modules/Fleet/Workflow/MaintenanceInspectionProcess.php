<?php

namespace Modules\Fleet\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Fleet\Actions\ManageMaintenance;
use Modules\Workflow\Contracts\WorkflowProcess;
use Modules\Workflow\Contracts\HasAmount;

class MaintenanceInspectionProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'maintenance-inspection';

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
        return 'Repair inspection and cost';
    }

    public function description(): string
    {
        return 'The priced findings of an inspection. Approval sends the parts and work to procurement as a requisition.';
    }

    public function summary(Model $subject): array
    {
        $subject->loadMissing(['unit', 'lines', 'supplier']);

        return [
            'title' => "{$subject->number} {$subject->unit?->label()}: ".number_format((float) $subject->estimate, 2),
            'lines' => array_values(array_filter([
                $subject->findings,
                ($subject->supplier?->name ?? $subject->workshop) ? 'Workshop: '.($subject->supplier?->name ?? $subject->workshop) : null,
                ...$subject->lines->map(fn ($l) => "{$l->description}: {$l->quantity} × ".number_format($l->unit_cost, 2))->all(),
            ])),
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
        app(ManageMaintenance::class)->inspectionApproved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageMaintenance::class)->inspectionRejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->estimate;
    }
}
