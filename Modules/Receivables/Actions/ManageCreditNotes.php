<?php

namespace Modules\Receivables\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Services\PostingService;
use Modules\Core\Notifications\Notice;
use Modules\Core\Support\Numbering;
use Modules\Receivables\Models\CreditNote;
use Modules\Receivables\Models\Invoice;
use Modules\Receivables\Models\ReceivableSetting;
use Modules\Receivables\Workflow\CreditNoteProcess;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageCreditNotes
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly PostingService $posting) {}

    public function create(Invoice $invoice, array $data, User $by): CreditNote
    {
        if (! in_array($invoice->status, ['open', 'partial'], true)) {
            throw ValidationException::withMessages(['invoice' => 'A credit note is given on an issued invoice that still has a balance.']);
        }
        $pending = (float) $invoice->creditNotes()->where('status', 'pending')->sum('amount');
        if (round((float) $data['amount'], 2) > round($invoice->balance() - $pending, 2)) {
            throw ValidationException::withMessages(['amount' => 'Only '.number_format($invoice->balance() - $pending, 2).' of this invoice can still be credited.']);
        }

        return DB::transaction(function () use ($invoice, $data, $by) {
            $note = CreditNote::create(['number' => Numbering::next(CreditNote::class, 'CN', $data['date']), 'invoice_id' => $invoice->id, 'date' => $data['date'],
                'amount' => round((float) $data['amount'], 2), 'reason' => $data['reason'], 'status' => 'pending', 'created_by' => $by->id]);
            if (! $this->workflow->start(CreditNoteProcess::KEY, $note, $by)) {
                $this->approved($note);
            }

            return $note->refresh();
        });
    }

    public function approved(CreditNote $note): void
    {
        DB::transaction(function () use ($note) {
            $invoice = $note->invoice()->with(['lines', 'customer'])->lockForUpdate()->first();
            if (round($note->amount, 2) > $invoice->balance()) {
                throw ValidationException::withMessages(['amount' => "{$invoice->number} now has only ".number_format($invoice->balance(), 2).' due.']);
            }
            $debits = [];
            $left = $note->amount;
            foreach ($invoice->lines->values() as $i => $l) {
                $share = $i === $invoice->lines->count() - 1 ? $left : round($note->amount * $l->amount / $invoice->total, 2);
                $left = round($left - $share, 2);
                if ($share > 0) {
                    $debits[] = ['account_id' => $l->account_id, 'debit' => $share, 'description' => "Credit: {$l->description}", 'department_id' => $l->department_id, 'budget_item_id' => $l->budget_item_id];
                }
            }
            $journal = $this->posting->post($note->date->toDateString(), "{$note->number} on {$invoice->number} {$invoice->customer->name}", [
                ...$debits, ['account_id' => ReceivableSetting::current()->receivable_account_id, 'credit' => $note->amount, 'description' => $invoice->customer->name],
            ], $note->creator, 'credit_note', $note);
            $note->update(['status' => 'approved', 'journal_entry_id' => $journal->id, 'decided_at' => now()]);
            $invoice->refreshBalance();
        });
    }

    public function rejected(CreditNote $note, ?string $comment, bool $withdrawn): void
    {
        $note->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
        if (! $withdrawn) {
            $note->creator?->notify(new Notice('Credit note not approved', "{$note->number} was not approved: {$comment}", "/receivables/invoices/{$note->invoice_id}", 'receipt'));
        }
    }

    public function withdraw(CreditNote $note, User $by): CreditNote
    {
        if ($note->status !== 'pending' || $note->created_by !== $by->id) {
            throw ValidationException::withMessages(['credit_note' => 'Only your credit note waiting for approval can be withdrawn.']);
        }
        $instance = $note->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($note, null, true);

        return $note->refresh();
    }
}
