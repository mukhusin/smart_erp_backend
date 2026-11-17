<?php

namespace Modules\Inventory\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Inventory\Actions\ManageStockDocuments;
use Modules\Inventory\Models\StockDocument;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

abstract class StockDocumentProcess implements HasAmount, WorkflowProcess
{
    public const KEYS = ['issue' => 'stock-issue', 'transfer' => 'stock-transfer', 'receipt' => 'stock-receipt', 'count' => 'stock-adjustment'];

    public static function keyFor(string $kind): string
    {
        return self::KEYS[$kind];
    }

    public function module(): string
    {
        return 'inventory';
    }

    /** @param StockDocument $subject */
    public function summary(Model $subject): array
    {
        $subject->loadMissing(['store', 'toStore', 'department', 'lines.item']);
        $lines = $subject->kind === 'count'
            ? $subject->lines->filter(fn ($l) => round($l->base_quantity, 4) != 0)->take(10)->map(fn ($l) => "{$l->item?->name}: counted {$l->quantity}, ledger {$l->system_quantity} ({$l->reason})")
            : $subject->lines->take(10)->map(fn ($l) => "{$l->item?->name}: {$l->quantity}");

        return [
            'title' => (StockDocument::KINDS[$subject->kind] ?? 'Stock document')." {$subject->number}".($subject->value ? ': '.number_format(abs((float) $subject->value), 2) : ''),
            'lines' => array_values(array_filter([
                'Store: '.$subject->store?->name.($subject->toStore ? " → {$subject->toStore->name}" : ''),
                $subject->reason ? 'Reason: '.(StockDocument::REASONS[$subject->reason] ?? $subject->reason) : null,
                $subject->department ? 'Department: '.$subject->department->name : null,
                ...$lines->values()->all(),
            ])),
            'link' => "/inventory/documents/{$subject->id}",
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return $subject->store?->company_branch_id;
    }

    public function departmentId(Model $subject): ?int
    {
        return $subject->department_id ?? $subject->store?->department_id;
    }

    public function employeeId(Model $subject): ?int
    {
        return null;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageStockDocuments::class)->approved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageStockDocuments::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }

    public function amount(Model $subject): ?float
    {
        return abs((float) $subject->value) ?: (float) $subject->lines->sum('value');
    }
}
