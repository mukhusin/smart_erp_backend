<?php

namespace Modules\Receivables\Actions;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\Numbering;
use Modules\Receivables\Models\Customer;
use Modules\Receivables\Models\InsuranceClaim;
use Modules\Receivables\Models\InsuranceClaimLine;
use Modules\Receivables\Models\Invoice;
use Modules\Receivables\Models\ReceivableSetting;

class ManageClaims
{
    public function __construct(private readonly ManageCreditNotes $notes, private readonly ManageInvoices $invoices) {}

    private function enabled(): void
    {
        if (! ReceivableSetting::current()->claims_enabled) {
            throw ValidationException::withMessages(['claim' => 'Insurance / third-party billing is off in billing settings.']);
        }
    }

    /** Unpaid invoices of the payer in the period that are not on another claim. */
    public function candidates(int $customerId, string $from, string $to, ?int $exceptClaim = null): Builder
    {
        return Invoice::where('customer_id', $customerId)->whereIn('status', ['open', 'partial'])->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->whereDoesntHave('claimLines', fn ($q) => $q->where(fn ($q) => $q->whereNull('resolution')->orWhere('resolution', '!=', 'resubmit'))
                ->whereHas('claim', fn ($q) => $q->where('status', '!=', 'cancelled')->when($exceptClaim, fn ($q, $id) => $q->whereKeyNot($id))));
    }

    public function create(array $data, User $by): InsuranceClaim
    {
        $this->enabled();
        $customer = Customer::where('is_active', true)->findOrFail($data['customer_id']);

        return DB::transaction(function () use ($customer, $data, $by) {
            $claim = InsuranceClaim::create(['number' => Numbering::next(InsuranceClaim::class, 'CLM', $data['period_to']), 'customer_id' => $customer->id, 'period_from' => $data['period_from'],
                'period_to' => $data['period_to'], 'status' => 'draft', 'notes' => $data['notes'] ?? null, 'created_by' => $by->id]);
            $this->fill($claim, $this->candidates($customer->id, $data['period_from'], $data['period_to'])->pluck('id')->all());

            return $claim->refresh();
        });
    }

    public function setInvoices(InsuranceClaim $claim, array $invoiceIds): InsuranceClaim
    {
        if ($claim->status !== 'draft') {
            throw ValidationException::withMessages(['claim' => 'Only a draft claim can be changed.']);
        }
        $allowed = $this->candidates($claim->customer_id, $claim->period_from->toDateString(), $claim->period_to->toDateString(), $claim->id)->whereIn('id', $invoiceIds)->pluck('id')->all();
        if (count($allowed) !== count(array_unique($invoiceIds))) {
            throw ValidationException::withMessages(['invoice_ids' => 'Some invoices are not unpaid invoices of this payer in the period, or are on another claim.']);
        }
        DB::transaction(function () use ($claim, $allowed) {
            $claim->lines()->delete();
            $this->fill($claim, $allowed);
        });

        return $claim->refresh();
    }

    private function fill(InsuranceClaim $claim, array $ids): void
    {
        foreach (Invoice::whereIn('id', $ids)->orderBy('date')->orderBy('id')->get() as $inv) {
            $claim->lines()->create(['invoice_id' => $inv->id, 'claimed' => $inv->balance()]);
        }
        $claim->update(['claimed' => round((float) $claim->lines()->sum('claimed'), 2)]);
    }

    public function submit(InsuranceClaim $claim, array $data): InsuranceClaim
    {
        $this->enabled();
        if ($claim->status !== 'draft') {
            throw ValidationException::withMessages(['claim' => 'This claim is already submitted.']);
        }
        if (! $claim->lines()->exists()) {
            throw ValidationException::withMessages(['claim' => 'Add the invoices to claim first.']);
        }
        $claim->update(['status' => 'submitted', 'submitted_on' => $data['submitted_on'], 'payer_reference' => $data['payer_reference'] ?? null]);

        return $claim->refresh();
    }

    /** @param list<array{line_id: int, approved: float|int|string, reason?: string|null}> $lines */
    public function respond(InsuranceClaim $claim, array $data): InsuranceClaim
    {
        if ($claim->status !== 'submitted') {
            throw ValidationException::withMessages(['claim' => 'Record the payer response on a submitted claim.']);
        }
        $given = collect($data['lines'])->keyBy('line_id');

        return DB::transaction(function () use ($claim, $data, $given) {
            foreach ($claim->lines as $n => $line) {
                $approved = round((float) ($given[$line->id]['approved'] ?? $line->claimed), 2);
                if ($approved < 0 || $approved > $line->claimed) {
                    throw ValidationException::withMessages(["lines.{$n}.approved" => "Between 0 and the claimed {$line->claimed}."]);
                }
                $rejected = round($line->claimed - $approved, 2);
                if ($rejected > 0 && empty($given[$line->id]['reason'])) {
                    throw ValidationException::withMessages(["lines.{$n}.reason" => 'Give the payer’s reason for the rejected part.']);
                }
                $line->update(['approved' => $approved, 'rejected' => $rejected, 'rejection_reason' => $rejected > 0 ? $given[$line->id]['reason'] : null]);
            }
            $claim->update(['status' => 'responded', 'responded_on' => $data['responded_on'], 'approved' => round((float) $claim->lines()->sum('approved'), 2),
                'rejected' => round((float) $claim->lines()->sum('rejected'), 2)]);
            $this->refresh($claim);

            return $claim->refresh();
        });
    }

    /** Handle the rejected part of a line: write it off, bill it to the patient / member, or keep it to claim again. */
    public function resolve(InsuranceClaimLine $line, array $data, User $by): InsuranceClaimLine
    {
        $claim = $line->claim;
        if ($claim->status !== 'responded' || $line->rejected <= 0 || $line->resolution) {
            throw ValidationException::withMessages(['line' => 'Only an unresolved rejected amount on a responded claim can be handled.']);
        }
        $invoice = $line->invoice()->with('lines')->first();
        $amount = round(min($line->rejected, $invoice->balance()), 2);

        return DB::transaction(function () use ($line, $claim, $invoice, $amount, $data, $by) {
            $update = ['resolution' => $data['action']];
            if (in_array($data['action'], ['written_off', 'rebilled'], true) && $amount > 0) {
                $note = $this->notes->create($invoice, ['date' => $data['date'], 'amount' => $amount,
                    'reason' => "Rejected on claim {$claim->number}: {$line->rejection_reason}"], $by);
                $update['credit_note_id'] = $note->id;
            }
            if ($data['action'] === 'rebilled') {
                $customer = Customer::where('is_active', true)->find($data['customer_id'] ?? 0);
                if (! $customer || $customer->id === $invoice->customer_id) {
                    throw ValidationException::withMessages(['customer_id' => 'Choose the patient or member to bill.']);
                }
                $left = $amount;
                $lines = [];
                foreach ($invoice->lines->values() as $i => $l) {
                    $share = $i === $invoice->lines->count() - 1 ? $left : round($amount * ($l->amount + $l->tax_amount) / $invoice->total, 2);
                    $left = round($left - $share, 2);
                    if ($share > 0) {
                        $lines[] = ['description' => $l->description, 'account_id' => $l->account_id, 'department_id' => $l->department_id, 'budget_item_id' => $l->budget_item_id, 'quantity' => 1,
                            'unit_price' => round($share * 100 / (100 + $l->tax_rate), 2), 'tax_code_id' => $l->tax_code_id];
                    }
                }
                $rebill = $this->invoices->save(null, ['customer_id' => $customer->id, 'date' => $data['date'], 'reference' => "Claim {$claim->number} / {$invoice->number}",
                    'beneficiary' => $invoice->beneficiary, 'member_no' => $invoice->member_no, 'notes' => "Not covered by {$claim->customer?->name}: {$line->rejection_reason}", 'lines' => $lines], $by);
                $update['rebill_invoice_id'] = $rebill->id;
            }
            $line->update($update);
            $this->refresh($claim);

            return $line->refresh();
        });
    }

    public function cancel(InsuranceClaim $claim): InsuranceClaim
    {
        if (! in_array($claim->status, ['draft', 'submitted'], true)) {
            throw ValidationException::withMessages(['claim' => 'Only a draft or submitted claim can be cancelled.']);
        }
        $claim->update(['status' => 'cancelled']);

        return $claim->refresh();
    }

    public function outstanding(InsuranceClaim $claim): float
    {
        $claim->loadMissing('lines.invoice');

        return round($claim->lines->sum(fn ($l) => $l->resolution === 'resubmit' ? 0 : min($l->approved ?? $l->claimed, max(0, $l->invoice?->balance() ?? 0))), 2);
    }

    /** Close a responded claim once every rejection is handled and the approved amounts are received. */
    public function refresh(InsuranceClaim $claim): void
    {
        if ($claim->status !== 'responded') {
            return;
        }
        $claim->load('lines.invoice');
        if ($claim->lines->every(fn ($l) => $l->rejected <= 0 || $l->resolution) && $this->outstanding($claim) <= 0) {
            $claim->update(['status' => 'closed']);
        }
    }
}
