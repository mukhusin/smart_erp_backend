<?php

namespace Modules\Procurement\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Procurement\Actions\ManageOrders;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class PurchaseOrderProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'purchase-order';

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
        return 'Purchase order';
    }

    public function description(): string
    {
        return 'An order to a supplier. On approval the money is committed on the budget lines.';
    }

    public function summary(Model $subject): array
    {
        $subject->loadMissing(['supplier', 'lines', 'requisition.department']);

        return [
            'title' => "{$subject->number} {$subject->supplier?->name}: ".number_format((float) $subject->total, 2),
            'lines' => array_values(array_filter([
                'Method: '.(\Modules\Procurement\Models\PurchaseOrder::METHODS[$subject->method] ?? $subject->method).($subject->method_reason ? " — {$subject->method_reason}" : ''),
                $subject->requisition ? "Requisition: {$subject->requisition->number} ({$subject->requisition->department?->name})" : null,
                ...$subject->lines->take(8)->map(fn ($l) => "{$l->description}: {$l->quantity} × ".number_format($l->unit_price, 2))->all(),
            ])),
            'link' => "/procurement/orders/{$subject->id}",
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return null;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->requisition?->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return null;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageOrders::class)->approved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageOrders::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->total;
    }
}
