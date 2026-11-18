<?php

namespace Modules\Receivables\Actions;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Services\PostingService;
use Modules\Core\Support\Numbering;
use Modules\Receivables\Models\Customer;
use Modules\Receivables\Models\Invoice;
use Modules\Receivables\Models\ReceivableSetting;
use Modules\Receivables\Support\RevenueLines;
use Modules\Receivables\Workflow\InvoiceProcess;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageInvoices
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly PostingService $posting, private readonly \Modules\Receivables\Support\CreditControl $credit) {}

    public function save(?Invoice $invoice, array $data, User $by): Invoice
    {
        if ($invoice && ! $invoice->editable()) {
            throw ValidationException::withMessages(['invoice' => 'Only a draft or returned invoice can be changed.']);
        }
        $customer = Customer::where('is_active', true)->find($data['customer_id']);
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => 'Choose an active customer.']);
        }
        RevenueLines::check($data['lines']);
        $terms = $customer->terms_days ?? ReceivableSetting::current()->default_terms_days;
        $due = $data['due_date'] ?? Carbon::parse($data['date'])->addDays($terms)->toDateString();

        return DB::transaction(function () use ($invoice, $data, $by, $customer, $due) {
            $invoice ??= new Invoice(['number' => Numbering::next(Invoice::class, 'INV', $data['date']), 'created_by' => $by->id, 'status' => 'draft']);
            $lines = [];
            foreach ($data['lines'] as $n => $l) {
                $amount = round(round((float) ($l['quantity'] ?? 1), 2) * round((float) $l['unit_price'], 2), 2);
                [$code, $rate] = \Modules\Accounting\Support\Vat::code($l['tax_code_id'] ?? null, "lines.{$n}.tax_code_id");
                $lines[] = ['description' => $l['description'], 'account_id' => $l['account_id'], 'department_id' => $l['department_id'] ?? null, 'budget_item_id' => $l['budget_item_id'] ?? null,
                    'quantity' => round((float) ($l['quantity'] ?? 1), 2), 'unit_price' => round((float) $l['unit_price'], 2), 'amount' => $amount,
                    'tax_code_id' => $code, 'tax_rate' => $rate, 'tax_amount' => \Modules\Accounting\Support\Vat::onNet($amount, $rate)];
            }
            $tax = round(array_sum(array_column($lines, 'tax_amount')), 2);
            $invoice->fill(['customer_id' => $customer->id, 'date' => $data['date'], 'due_date' => $due, 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null,
                'beneficiary' => $data['beneficiary'] ?? null, 'member_no' => $data['member_no'] ?? null,
                'total' => round(array_sum(array_column($lines, 'amount')) + $tax, 2), 'tax_total' => $tax])->save();
            $invoice->lines()->delete();
            foreach ($lines as $l) {
                $invoice->lines()->create($l);
            }

            return $invoice->refresh();
        });
    }

    public function issue(Invoice $invoice, User $by): Invoice
    {
        if (! $invoice->editable()) {
            throw ValidationException::withMessages(['invoice' => 'This invoice is already issued.']);
        }
        if ($invoice->total <= 0) {
            throw ValidationException::withMessages(['invoice' => 'The invoice has no amount.']);
        }
        if (! ReceivableSetting::current()->receivable_account_id) {
            throw ValidationException::withMessages(['settings' => 'Choose the receivables account in billing settings first.']);
        }
        if ($invoice->tax_total > 0) {
            \Modules\Accounting\Models\VatSetting::ready('output') ?? throw ValidationException::withMessages(['vat' => 'This invoice has VAT but the company is not VAT registered; remove the VAT codes.']);
        }

        $overLimit = $this->credit->check($invoice);

        return DB::transaction(function () use ($invoice, $by, $overLimit) {
            $invoice->update(['status' => 'pending', 'over_limit' => $overLimit, 'decision_comment' => null]);
            if (! $this->workflow->start(InvoiceProcess::KEY, $invoice, $by)) {
                $this->post($invoice, $by);
            }

            return $invoice->refresh();
        });
    }

    public function post(Invoice $invoice, ?User $by = null): void
    {
        DB::transaction(function () use ($invoice, $by) {
            $invoice->load(['lines', 'customer', 'creator']);
            $lines = [['account_id' => ReceivableSetting::current()->receivable_account_id, 'debit' => $invoice->total, 'description' => $invoice->customer->name]];
            foreach ($invoice->lines as $l) {
                $lines[] = ['account_id' => $l->account_id, 'credit' => $l->amount, 'description' => $l->description, 'department_id' => $l->department_id, 'budget_item_id' => $l->budget_item_id];
            }
            if ($invoice->tax_total > 0) {
                $lines[] = ['account_id' => \Modules\Accounting\Models\VatSetting::current()->output_account_id, 'credit' => $invoice->tax_total, 'description' => "Output VAT {$invoice->number}"];
            }
            $journal = $this->posting->post($invoice->date->toDateString(), "{$invoice->number} {$invoice->customer->name}", $lines, $by ?? $invoice->creator, 'invoice', $invoice);
            $invoice->update(['status' => 'open', 'journal_entry_id' => $journal->id, 'issued_at' => now()]);
            $invoice->refreshBalance();
        });
    }

    public function rejected(Invoice $invoice, ?string $comment, bool $withdrawn): void
    {
        $invoice->update(['status' => $withdrawn ? 'draft' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment]);
    }

    public function withdraw(Invoice $invoice, User $by): Invoice
    {
        if ($invoice->status !== 'pending' || $invoice->created_by !== $by->id) {
            throw ValidationException::withMessages(['invoice' => 'Only your invoice waiting for approval can be withdrawn.']);
        }
        $instance = $invoice->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($invoice, null, true);

        return $invoice->refresh();
    }

    public function void(Invoice $invoice, User $by, string $reason): Invoice
    {
        if (! in_array($invoice->status, Invoice::ISSUED, true) || $invoice->paid > 0 || $invoice->credited > 0) {
            throw ValidationException::withMessages(['invoice' => 'Only an issued invoice with no receipts or credit notes can be voided. Use a credit note instead.']);
        }
        DB::transaction(function () use ($invoice, $by, $reason) {
            if ($invoice->journal) {
                $this->posting->reverse($invoice->journal, now()->toDateString(), $by, "{$invoice->number} voided: {$reason}");
            }
            $invoice->update(['status' => 'void', 'decision_comment' => $reason]);
        });

        return $invoice->refresh();
    }

    public function delete(Invoice $invoice): void
    {
        if ($invoice->status !== 'draft') {
            throw ValidationException::withMessages(['invoice' => 'Only a draft invoice can be deleted.']);
        }
        $invoice->delete();
    }
}
