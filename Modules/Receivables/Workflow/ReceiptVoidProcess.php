<?php

namespace Modules\Receivables\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Receivables\Actions\ManageReceipts;
use Modules\Workflow\Contracts\HasAmount;
use Modules\Workflow\Contracts\WorkflowProcess;

class ReceiptVoidProcess implements HasAmount, WorkflowProcess
{
    public const KEY = 'receipt-void';

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
        return 'Void a receipt';
    }

    public function description(): string
    {
        return 'Cancelling money already received. On approval the receipt journal is reversed and the invoices it paid are due again.';
    }

    /** @param \Modules\Receivables\Models\Receipt $subject */
    public function summary(Model $subject): array
    {
        return [
            'title' => "Void {$subject->number}: ".number_format((float) $subject->amount, 2),
            'lines' => ["From: {$subject->payer_name}", 'Date: '.$subject->date?->toDateString(), "Reason: {$subject->void_reason}"],
            'link' => "/receivables/receipts/{$subject->id}",
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
        app(ManageReceipts::class)->voided($subject);
    }

    public function onRejected(Model $subject, ?string $comment): void
    {
        app(ManageReceipts::class)->voidRejected($subject);
    }

    public function amount(Model $subject): ?float
    {
        return (float) $subject->amount;
    }
}
