<?php

namespace Modules\Fleet\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Fleet\Actions\ManageUnits;
use Modules\Fleet\Models\Reading;
use Modules\Workflow\Contracts\WorkflowProcess;

class ReadingCorrectionProcess implements WorkflowProcess
{
    public const KEY = 'fleet-reading-correction';

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
        return 'Odometer or hours correction';
    }

    public function description(): string
    {
        return 'Changing a vehicle odometer or generator hours to a value the normal readings do not allow (for example after a wrong entry or a replaced meter).';
    }

    /** @param Reading $subject */
    public function summary(Model $subject): array
    {
        $unit = $subject->unit;
        $u = $unit?->unit();

        return [
            'title' => "Correct {$unit?->label()}: ".number_format((float) $subject->previous, 1)." → ".number_format($subject->reading, 1)." {$u}",
            'lines' => array_values(array_filter(['Reason: '.$subject->note])),
            'link' => "/fleet/units/{$subject->fleet_unit_id}",
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
        app(ManageUnits::class)->correctionApproved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageUnits::class)->correctionRejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }
}
