<?php

namespace Modules\Inventory\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Inventory\Actions\ManageStoreRequisitions;
use Modules\Inventory\Models\StoreRequisition;
use Modules\Workflow\Contracts\WorkflowProcess;

class StoreRequisitionProcess implements WorkflowProcess
{
    public const KEY = 'store-requisition';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'inventory';
    }

    public function name(): string
    {
        return 'Store request';
    }

    public function description(): string
    {
        return 'A staff member or department asks for items from a store; the supervisor approves before the store issues.';
    }

    /** @param StoreRequisition $subject */
    public function summary(Model $subject): array
    {
        $subject->loadMissing(['store', 'department', 'requester', 'lines.item', 'lines.unit']);

        return [
            'title' => "{$subject->number} from {$subject->requester?->display_name}",
            'lines' => array_values(array_filter([
                "Store: {$subject->store?->name} · {$subject->department?->name}",
                $subject->purpose ? 'Purpose: '.$subject->purpose : null,
                ...$subject->lines->take(10)->map(fn ($l) => "{$l->item?->name}: {$l->quantity} ".($l->unit?->code ?? ''))->all(),
            ])),
            'link' => "/inventory/requests/{$subject->id}",
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return $subject->store?->company_branch_id;
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
        app(ManageStoreRequisitions::class)->approved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageStoreRequisitions::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }
}
