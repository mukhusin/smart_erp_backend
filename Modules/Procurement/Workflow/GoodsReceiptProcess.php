<?php

namespace Modules\Procurement\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Procurement\Actions\ManageReceipts;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class GoodsReceiptProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'goods-receipt';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'procurement';
    }

    public function name(): string
    {
        return 'Goods received (inspection)';
    }

    public function description(): string
    {
        return 'Delivered goods are inspected and accepted. On approval stock or assets are recorded and the supplier bill is raised.';
    }

    public function summary(Model $subject): array
    {
        $subject->loadMissing(['order.supplier', 'lines.orderLine']);

        return [
            'title' => "{$subject->number} from {$subject->order?->supplier?->name}: ".number_format((float) $subject->total, 2),
            'lines' => array_values(array_filter([
                "Order: {$subject->order?->number}".($subject->delivery_note ? " · delivery note {$subject->delivery_note}" : ''),
                ...$subject->lines->map(fn ($l) => "{$l->orderLine?->description}: accepted {$l->accepted_quantity}".($l->rejected_quantity > 0 ? ", rejected {$l->rejected_quantity} ({$l->rejection_reason})" : ''))->all(),
            ])),
            'link' => "/procurement/receipts/{$subject->id}",
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return null;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->order?->requisition?->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return null;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageReceipts::class)->accepted($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageReceipts::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->total;
    }
}
