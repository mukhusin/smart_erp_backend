<?php

namespace Modules\Fleet\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Fleet\Actions\ManageFuel;
use Modules\Fleet\Models\FuelRequest;
use Modules\Workflow\Contracts\WorkflowProcess;

class FuelRequestProcess implements WorkflowProcess
{
    public const KEY = 'fuel-request';

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
        return 'Fuel request';
    }

    public function description(): string
    {
        return 'Fuel for a vehicle or generator, checked against its tank and usual consumption. The person who asks cannot approve or issue it.';
    }

    /** @param FuelRequest $subject */
    public function summary(Model $subject): array
    {
        $subject->loadMissing(['unit', 'store', 'requester']);
        $u = $subject->unit?->unit();

        return [
            'title' => "{$subject->number} {$subject->litres} L for {$subject->unit?->label()}",
            'lines' => array_values(array_filter([
                "From {$subject->store?->name} · reading ".number_format($subject->reading, 1)." {$u}",
                $subject->purpose,
                'Asked by '.$subject->requester?->display_name,
                ...collect($subject->warnings ?? [])->map(fn ($w) => "⚠ {$w}")->all(),
            ])),
            'link' => "/fleet/fuel/{$subject->id}",
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return $subject->unit?->company_branch_id;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return $subject->requester?->employee?->id;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageFuel::class)->approved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageFuel::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }
}
