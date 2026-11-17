<?php

namespace Modules\Fleet\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Fleet\Actions\ManageTransport;
use Modules\Fleet\Models\TransportRequest;
use Modules\Workflow\Contracts\WorkflowProcess;

class TransportRequestProcess implements WorkflowProcess
{
    public const KEY = 'transport-request';

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
        return 'Transport request';
    }

    public function description(): string
    {
        return 'Staff ask for a vehicle for official travel or goods. Once approved, the transport office allocates a vehicle and driver or a transport company.';
    }

    /** @param TransportRequest $subject */
    public function summary(Model $subject): array
    {
        $subject->loadMissing(['requester', 'department']);

        return [
            'title' => "{$subject->number} {$subject->purpose} → {$subject->destination}",
            'lines' => array_values(array_filter([
                $subject->departs_at->format('d M Y H:i').' to '.$subject->returns_at->format('d M Y H:i'),
                "{$subject->passengers} passenger(s)".($subject->cargo ? " · cargo: {$subject->cargo}" : ''),
                'Asked by '.$subject->requester?->display_name.($subject->department ? " ({$subject->department->name})" : ''),
            ])),
            'link' => "/fleet/requests/{$subject->id}",
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return $subject->employee?->company_branch_id;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return $subject->employee_id;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageTransport::class)->approved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageTransport::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }
}
