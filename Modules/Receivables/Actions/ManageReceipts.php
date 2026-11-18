<?php

namespace Modules\Receivables\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\CashBankAccount;
use Modules\Accounting\Services\PostingService;
use Modules\Core\Support\Numbering;
use Modules\Receivables\Models\Customer;
use Modules\Receivables\Models\Invoice;
use Modules\Receivables\Models\Receipt;
use Modules\Receivables\Models\ReceivableSetting;
use Modules\Receivables\Support\RevenueLines;
use Modules\Receivables\Workflow\ReceiptVoidProcess;
use Modules\Workflow\Services\WorkflowEngine;

class ManageReceipts
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly PostingService $posting) {}

    public function create(array $data, User $by): Receipt
    {
        $bank = CashBankAccount::where('is_active', true)->find($data['cash_bank_account_id']);
        if (! $bank || ! $bank->account_id) {
            throw ValidationException::withMessages(['cash_bank_account_id' => 'Choose an active bank or cash account linked to a ledger account.']);
        }
        $customer = ! empty($data['customer_id']) ? Customer::find($data['customer_id']) : null;

        return $data['kind'] === 'invoice' ? $this->againstInvoices($data, $by, $bank, $customer) : $this->direct($data, $by, $bank, $customer);
    }

    private function againstInvoices(array $data, User $by, CashBankAccount $bank, ?Customer $customer): Receipt
    {
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => 'Choose the customer who pays.']);
        }
        $receivable = ReceivableSetting::current()->receivable_account_id;
        if (! $receivable) {
            throw ValidationException::withMessages(['settings' => 'Choose the receivables account in billing settings first.']);
        }
        $allocations = array_values(array_filter($data['allocations'] ?? [], fn ($a) => round((float) $a['amount'], 2) > 0));
        if (! $allocations) {
            throw ValidationException::withMessages(['allocations' => 'Enter the amount paid on at least one invoice.']);
        }

        return DB::transaction(function () use ($data, $by, $bank, $customer, $allocations, $receivable) {
            $invoices = Invoice::where('customer_id', $customer->id)->whereIn('id', array_column($allocations, 'invoice_id'))->lockForUpdate()->get()->keyBy('id');
            foreach ($allocations as $i => $a) {
                $invoice = $invoices[$a['invoice_id']] ?? null;
                if (! $invoice || ! in_array($invoice->status, ['open', 'partial'], true)) {
                    throw ValidationException::withMessages(["allocations.$i.invoice_id" => 'Choose an unpaid invoice of this customer.']);
                }
                if (round((float) $a['amount'], 2) > $invoice->balance()) {
                    throw ValidationException::withMessages(["allocations.$i.amount" => "{$invoice->number}: only ".number_format($invoice->balance(), 2).' is due.']);
                }
            }
            $amount = round(array_sum(array_map(fn ($a) => (float) $a['amount'], $allocations)), 2);
            $receipt = $this->make($data, $by, $bank, $customer, $amount);
            foreach ($allocations as $a) {
                $receipt->allocations()->create(['invoice_id' => $a['invoice_id'], 'amount' => round((float) $a['amount'], 2)]);
            }
            $this->postJournal($receipt, $by, [['account_id' => $receivable, 'credit' => $amount, 'description' => $customer->name.': '.$invoices->pluck('number')->join(', ')]]);
            foreach ($invoices as $invoice) {
                $invoice->refreshBalance();
            }

            return $receipt->refresh();
        });
    }

    private function direct(array $data, User $by, CashBankAccount $bank, ?Customer $customer): Receipt
    {
        $lines = array_values(array_filter($data['lines'] ?? [], fn ($l) => round((float) ($l['amount'] ?? 0), 2) > 0));
        if (! $lines) {
            throw ValidationException::withMessages(['lines' => 'Enter what was received and the amount.']);
        }
        RevenueLines::check($lines, 'lines', false);

        return DB::transaction(function () use ($data, $by, $bank, $customer, $lines) {
            $amount = round(array_sum(array_map(fn ($l) => (float) $l['amount'], $lines)), 2);
            $receipt = $this->make($data, $by, $bank, $customer, $amount);
            $credits = [];
            $vat = 0.0;
            foreach ($lines as $n => $l) {
                [$code, $rate] = \Modules\Accounting\Support\Vat::code($l['tax_code_id'] ?? null, "lines.{$n}.tax_code_id");
                $row = ['description' => $l['description'], 'account_id' => $l['account_id'], 'department_id' => $l['department_id'] ?? null, 'budget_item_id' => $l['budget_item_id'] ?? null,
                    'amount' => round((float) $l['amount'], 2), 'tax_code_id' => $code];
                $row['tax_amount'] = \Modules\Accounting\Support\Vat::inGross($row['amount'], $rate);
                $vat += $row['tax_amount'];
                $receipt->lines()->create($row);
                $credits[] = ['account_id' => $row['account_id'], 'credit' => round($row['amount'] - $row['tax_amount'], 2), 'description' => $row['description'], 'department_id' => $row['department_id'], 'budget_item_id' => $row['budget_item_id']];
            }
            if ($vat > 0) {
                $credits[] = ['account_id' => \Modules\Accounting\Models\VatSetting::ready('output')->output_account_id, 'credit' => round($vat, 2), 'description' => "Output VAT {$receipt->number}"];
            }
            $this->postJournal($receipt, $by, $credits);

            return $receipt->refresh();
        });
    }

    private function make(array $data, User $by, CashBankAccount $bank, ?Customer $customer, float $amount): Receipt
    {
        $payer = trim((string) ($data['payer_name'] ?? '')) ?: $customer?->name;
        if (! $payer) {
            throw ValidationException::withMessages(['payer_name' => 'Enter who paid.']);
        }

        return Receipt::create([
            'number' => Numbering::next(Receipt::class, 'RCT', $data['date']), 'kind' => $data['kind'], 'customer_id' => $customer?->id, 'payer_name' => mb_substr($payer, 0, 200),
            'date' => $data['date'], 'cash_bank_account_id' => $bank->id, 'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'amount' => $amount,
            'notes' => $data['notes'] ?? null, 'status' => 'posted', 'created_by' => $by->id,
        ]);
    }

    private function postJournal(Receipt $receipt, User $by, array $credits): void
    {
        $lines = [['account_id' => $receipt->bank->account_id, 'debit' => $receipt->amount, 'description' => "{$receipt->number} {$receipt->payer_name}"], ...$credits];
        $journal = $this->posting->post($receipt->date->toDateString(), "{$receipt->number} received from {$receipt->payer_name}", $lines, $by, 'receipt', $receipt);
        $receipt->update(['journal_entry_id' => $journal->id]);
    }

    public function requestVoid(Receipt $receipt, User $by, string $reason): Receipt
    {
        if ($receipt->status !== 'posted') {
            throw ValidationException::withMessages(['receipt' => 'Only a received receipt can be voided.']);
        }

        return DB::transaction(function () use ($receipt, $by, $reason) {
            $receipt->update(['status' => 'void_pending', 'void_reason' => $reason]);
            if (! $this->workflow->start(ReceiptVoidProcess::KEY, $receipt, $by)) {
                $this->voided($receipt, $by);
            }

            return $receipt->refresh();
        });
    }

    public function voided(Receipt $receipt, ?User $by = null): void
    {
        DB::transaction(function () use ($receipt, $by) {
            if ($receipt->journal) {
                $this->posting->reverse($receipt->journal, now()->toDateString(), $by ?? $receipt->creator, "{$receipt->number} voided: {$receipt->void_reason}");
            }
            $receipt->update(['status' => 'voided', 'voided_at' => now()]);
            foreach ($receipt->allocations()->with('invoice')->get() as $a) {
                $a->invoice->refreshBalance();
            }
        });
    }

    public function voidRejected(Receipt $receipt): void
    {
        $receipt->update(['status' => 'posted']);
    }
}
