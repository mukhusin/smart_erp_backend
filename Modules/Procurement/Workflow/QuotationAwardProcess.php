<?php

namespace Modules\Procurement\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Procurement\Actions\ManageQuotations;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class QuotationAwardProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'quotation-award';

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
        return 'Award of quotations';
    }

    public function description(): string
    {
        return 'Procurement proposes the winning quotation for each line. On approval the purchase orders are drafted.';
    }

    public function summary(Model $subject): array
    {
        $subject->loadMissing(['requisition.department', 'quotations.supplier', 'quotations.lines']);
        $won = $subject->quotations->map(fn ($q) => [$q->supplier?->name, round($q->lines->where('awarded', true)->sum('amount'), 2)])->filter(fn ($x) => $x[1] > 0);

        return [
            'title' => "{$subject->number} award: ".number_format($won->sum(fn ($x) => $x[1]), 2),
            'lines' => array_values(array_filter([
                "Requisition: {$subject->requisition?->number} {$subject->requisition?->title} ({$subject->requisition?->department?->name})",
                'Quotations received: '.$subject->quotations->count(),
                ...$won->map(fn ($x) => "{$x[0]}: ".number_format($x[1], 2))->values()->all(),
                $subject->award_comment ? 'Note: '.$subject->award_comment : null,
            ])),
            'link' => "/procurement/rfqs/{$subject->id}",
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
        app(ManageQuotations::class)->awarded($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageQuotations::class)->awardRejected($subject, $comment);
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->quotations()->with('lines')->get()->sum(fn ($q) => $q->lines->where('awarded', true)->sum('amount'));
    }
}
