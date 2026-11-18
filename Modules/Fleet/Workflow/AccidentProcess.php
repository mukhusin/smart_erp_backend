<?php

namespace Modules\Fleet\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Fleet\Actions\ManageMaintenance;
use Modules\Workflow\Contracts\WorkflowProcess;

class AccidentProcess implements WorkflowProcess
{
    public const KEY = 'fleet-accident';

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
        return 'Vehicle accident';
    }

    public function description(): string
    {
        return 'A reported accident is reviewed before the damage is assessed (repair, write off) and any insurance claim made.';
    }

    public function summary(Model $subject): array
    {
        $subject->loadMissing(['unit', 'driver.employee', 'reporter']);

        return [
            'title' => "{$subject->number} {$subject->unit?->label()} at {$subject->location}",
            'lines' => array_values(array_filter([
                $subject->happened_at->format('d M Y H:i').($subject->injuries ? ' · people injured' : ''),
                $subject->description,
                $subject->driver ? 'Driver: '.$subject->driver->name() : null,
                $subject->police_report ? 'Police report: '.$subject->police_report : null,
            ])),
            'link' => "/fleet/accidents/{$subject->id}",
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
        app(ManageMaintenance::class)->accidentReviewed($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageMaintenance::class)->accidentRejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }
}
