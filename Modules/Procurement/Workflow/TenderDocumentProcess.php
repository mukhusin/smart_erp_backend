<?php

namespace Modules\Procurement\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Procurement\Actions\ManageTenders;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class TenderDocumentProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'tender-document';

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
        return 'Tender document';
    }

    public function description(): string
    {
        return 'A tender document before it goes out to bidders.';
    }

    /** @param \Modules\Procurement\Models\Tender $subject */
    public function summary(Model $subject): array
    {
        return ['title' => "Tender document {$subject->number}: {$subject->title}", 'lines' => [($subject->method?->name ?? '').' · closes '.$subject->closing_at->format('d M Y H:i'), $subject->lines()->count().' line(s) in '.count(array_unique($subject->lines()->pluck('lot')->all())).' lot(s)'], 'link' => "/procurement/tenders/{$subject->id}"];
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

    public function amount(Model $subject): ?float
    {
        return (float) $subject->requisition?->total;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageTenders::class)->documentApproved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageTenders::class)->documentRejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }
}
