<?php

namespace Modules\Receivables\Support;

use Illuminate\Validation\ValidationException;
use Modules\Receivables\Models\Customer;
use Modules\Receivables\Models\Invoice;
use Modules\Receivables\Models\ReceivableSetting;

class CreditControl
{
    /** What the customer owes plus invoices waiting for approval, without the given invoice. */
    public function exposure(Customer $customer, ?int $exceptInvoice = null): float
    {
        $rows = Invoice::where('customer_id', $customer->id)->whereIn('status', ['pending', 'open', 'partial'])->when($exceptInvoice, fn ($q, $id) => $q->whereKeyNot($id))
            ->get(['total', 'paid', 'credited']);

        return round($rows->sum(fn ($i) => $i->total - $i->paid - $i->credited), 2);
    }

    public function oldestOverdueDays(Customer $customer): int
    {
        $due = Invoice::where('customer_id', $customer->id)->whereIn('status', ['open', 'partial'])->whereDate('due_date', '<', today())->min('due_date');

        return $due ? (int) \Illuminate\Support\Carbon::parse($due)->diffInDays(today()) : 0;
    }

    /** @return array{available: float|null, exposure: float, overdue_days: int} */
    public function position(Customer $customer): array
    {
        $exposure = $this->exposure($customer);

        return ['available' => $customer->credit_limit === null ? null : round($customer->credit_limit - $exposure, 2), 'exposure' => $exposure, 'overdue_days' => $this->oldestOverdueDays($customer)];
    }

    /** @return list<string> */
    public function problems(Invoice $invoice): array
    {
        $c = $invoice->customer;
        $s = ReceivableSetting::current();
        $out = [];
        if ($c->on_hold) {
            $out[] = "{$c->name} is on credit hold.";
        }
        if ($c->credit_limit !== null) {
            $after = round($this->exposure($c, $invoice->id) + $invoice->total, 2);
            if ($after > $c->credit_limit + 0.005) {
                $out[] = "{$c->name} would owe ".number_format($after, 2).', above the credit limit of '.number_format($c->credit_limit, 2).'.';
            }
        }
        if ($s->credit_overdue_days && ($days = $this->oldestOverdueDays($c)) > $s->credit_overdue_days) {
            $out[] = "{$c->name} has an invoice {$days} days overdue (allowed {$s->credit_overdue_days}).";
        }

        return $out;
    }

    /** Applies the credit check on issuing; returns true when the invoice is over limit in warn mode. */
    public function check(Invoice $invoice): bool
    {
        $mode = ReceivableSetting::current()->credit_check;
        if ($mode === 'off') {
            return false;
        }
        $problems = $this->problems($invoice->loadMissing('customer'));
        if ($problems && $mode === 'block') {
            throw ValidationException::withMessages(['invoice' => implode(' ', $problems).' Collect payment, raise the limit or release the hold first.']);
        }

        return $problems !== [];
    }
}
