<?php

namespace Modules\Receivables\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Receivables\Actions\ManageCreditNotes;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class CreditNoteProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'credit-note';

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'receivables';
    }

    public function name(): string
    {
        return 'Credit note';
    }

    public function description(): string
    {
        return 'Reduces what a customer owes on an invoice (wrong charge, discount, insurer rejection). On approval the revenue is reversed.';
    }

    /** @param \Modules\Receivables\Models\CreditNote $subject */
    public function summary(Model $subject): array
    {
        $subject->loadMissing(['invoice.customer']);

        return [
            'title' => "{$subject->number}: ".number_format((float) $subject->amount, 2),
            'lines' => ["Invoice: {$subject->invoice?->number} {$subject->invoice?->customer?->name} (".number_format((float) $subject->invoice?->total, 2).')', "Reason: {$subject->reason}"],
            'link' => "/receivables/invoices/{$subject->invoice_id}",
        ];
    }

    public function branchId(Model $subject): ?int
    {
        return null;
    }

    public function departmentId(Model $subject): ?int
    {
        return null;
    }

    public function employeeId(Model $subject): ?int
    {
        return null;
    }

    public function onApproved(Model $subject): void
    {
        app(ManageCreditNotes::class)->approved($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageCreditNotes::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->amount;
    }
}
