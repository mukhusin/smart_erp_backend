<?php

namespace Modules\Receivables\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Receivables\Actions\ManageInvoices;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class InvoiceProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'customer-invoice';

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
        return 'Customer invoice';
    }

    public function description(): string
    {
        return 'Optional check of an invoice before it is issued to the customer and posted to the ledger. Without steps, invoices are issued at once.';
    }

    /** @param \Modules\Receivables\Models\Invoice $subject */
    public function summary(Model $subject): array
    {
        $subject->loadMissing(['customer', 'lines']);

        return [
            'title' => "{$subject->number} {$subject->customer?->name}: ".number_format((float) $subject->total, 2),
            'lines' => [...$subject->lines->map(fn ($l) => $l->description.': '.number_format($l->amount, 2))->all(), 'Due: '.$subject->due_date?->toDateString()],
            'link' => "/receivables/invoices/{$subject->id}",
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
        app(ManageInvoices::class)->post($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageInvoices::class)->rejected($subject, $comment, $comment === 'Withdrawn by the requester.');
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->total;
    }
}
