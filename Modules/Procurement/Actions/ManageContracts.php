<?php

namespace Modules\Procurement\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Services\PostingService;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Services\BudgetService;
use Modules\Core\Notifications\Notice;
use Modules\Core\Support\Numbering;
use Modules\Payments\Models\PaymentRequest;
use Modules\Procurement\Models\Contract;
use Modules\Procurement\Models\ContractCertificate;
use Modules\Procurement\Models\ContractMilestone;
use Modules\Procurement\Models\ContractVariation;
use Modules\Procurement\Models\ProcurementSetting;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\TenderAward;
use Modules\Procurement\Workflow\ContractCertificateProcess;
use Modules\Procurement\Workflow\ContractVariationProcess;
use Modules\Procurement\Workflow\ContractVettingProcess;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * Contract from an award: vetting → signed (the requisition's hold becomes the contract's commitment) → call-off
 * orders (goods) or certificates (works, services, consultancy) with retention, damages and advance recovery →
 * variations within a limit → close. Contract balance = value + variations − ordered or certified.
 */
class ManageContracts
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly BudgetService $budgets, private readonly PostingService $posting, private readonly ManageRequisitions $requisitions) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public static function categoryFor(string $documentType, ?string $methodCategory): string
    {
        return match (true) {
            in_array($documentType, ['small_works', 'large_works', 'design_build', 'plant'], true) || $methodCategory === 'works' => 'works',
            str_starts_with($documentType, 'consultancy') || $methodCategory === 'consultancy' => 'consultancy',
            $documentType === 'services' || $methodCategory === 'services' => 'services',
            default => 'goods',
        };
    }

    public function fromAward(TenderAward $award, array $data, User $by): Contract
    {
        $t = $award->tender()->with(['method', 'lines.requisitionLine', 'requisition'])->first();
        if (! $award->accepted_at) {
            $this->fail('award', 'Record the winner\'s acceptance of the award letter first.');
        }
        if ($award->contract()->exists()) {
            $this->fail('award', 'This award already has a contract.');
        }
        $bid = $award->bid()->with('lines.tenderLine')->first();
        $lotLines = $t->lines->where('lot', $award->lot);
        $bidPrice = $bid->priceFor($award->lot);
        $factor = $bidPrice > 0 ? $award->amount / $bidPrice : 1;

        return DB::transaction(function () use ($award, $data, $by, $t, $bid, $lotLines, $factor) {
            $c = Contract::create(['number' => Numbering::next(Contract::class, 'CON'), 'tender_id' => $t->id, 'tender_award_id' => $award->id, 'purchase_requisition_id' => $t->purchase_requisition_id,
                'supplier_id' => $award->supplier_id, 'title' => $data['title'] ?? "{$t->title} ({$award->lot})", 'category' => $data['category'] ?? self::categoryFor($t->document_type, $t->method->category),
                'status' => 'draft', 'created_by' => $by->id, 'currency' => $bid->currency] + $this->terms($data));
            foreach ($lotLines as $tl) {
                $rl = $tl->requisitionLine;
                $price = round((float) $bid->lines->firstWhere('tender_line_id', $tl->id)->unit_price * (1 - $bid->discount_percent / 100) * $factor, 2);
                $c->lines()->create(['purchase_requisition_line_id' => $rl->id, 'item_id' => $rl->item_id, 'description' => $rl->description, 'unit_id' => $rl->unit_id,
                    'quantity' => $tl->quantity, 'unit_price' => $price, 'amount' => round($price * $tl->quantity, 2), 'budget_item_id' => $rl->budget_item_id]);
            }
            $c->update(['value' => round((float) $c->lines()->sum('amount'), 2)]);
            $this->saveMilestones($c, $data);

            return $c->refresh();
        });
    }

    private function terms(array $data): array
    {
        return collect($data)->only(['type', 'start_date', 'end_date', 'exchange_rate', 'retention_percent', 'damages_percent_per_day', 'damages_cap_percent', 'security_type', 'security_amount',
            'security_reference', 'security_expiry'])->filter(fn ($v) => $v !== null)->all();
    }

    private function saveMilestones(Contract $c, array $data): void
    {
        if (! array_key_exists('milestones', $data)) {
            return;
        }
        $c->milestones()->whereNull('payment_request_id')->delete();
        foreach ($data['milestones'] ?? [] as $m) {
            $c->milestones()->create(['name' => $m['name'], 'kind' => $m['kind'], 'amount' => round((float) $m['amount'], 2), 'due_date' => $m['due_date'] ?? null, 'status' => 'planned']);
        }
    }

    public function update(Contract $c, array $data): Contract
    {
        if (! in_array($c->status, ['draft', 'rejected'], true)) {
            $this->fail('contract', 'A signed contract changes only through a variation.');
        }

        return DB::transaction(function () use ($c, $data) {
            $c->update($this->terms($data) + collect($data)->only(['title', 'category'])->all());
            $this->saveMilestones($c, $data);

            return $c->refresh();
        });
    }

    public function submit(Contract $c, User $by): Contract
    {
        if (! in_array($c->status, ['draft', 'rejected'], true)) {
            $this->fail('contract', 'This contract is already submitted.');
        }
        if (! $c->start_date || ! $c->end_date || $c->end_date->lt($c->start_date)) {
            $this->fail('end_date', 'Give the start and end dates.');
        }
        $milestones = round((float) $c->milestones()->whereIn('kind', ['advance', 'delivery', 'certificate'])->sum('amount'), 2);
        if ($milestones > $c->value) {
            $this->fail('milestones', 'The payment milestones add up to more than the contract value.');
        }

        return DB::transaction(function () use ($c, $by) {
            $c->update(['status' => 'vetting', 'decision_comment' => null]);
            if (! $this->workflow->start(ContractVettingProcess::KEY, $c, $by)) {
                $this->signed($c);
            }

            return $c->refresh();
        });
    }

    /** Vetted and signed: the requisition's hold for these lines becomes the contract's commitment. */
    public function signed(Contract $c): void
    {
        DB::transaction(function () use ($c) {
            $c->load(['lines', 'requisition.lines', 'creator', 'tender']);
            $by = $c->creator;
            if ($c->requisition) {
                $this->requisitions->ordered($c->requisition, $c->lines->groupBy('purchase_requisition_line_id')->map(fn ($g) => $g->sum('quantity'))->all(), $by);
            }
            $amounts = [];
            foreach ($c->lines as $l) {
                $amounts[$l->budget_item_id] = round(($amounts[$l->budget_item_id] ?? 0) + $l->amount, 2);
            }
            $this->budgets->reserve($c, $amounts, $this->budgets->mode() !== 'block', $by);
            $this->budgets->commit($c, $by);
            $c->update(['status' => 'active', 'signed_on' => $c->signed_on ?? today()]);
            if ($c->tender && $c->tender->awards()->get()->every(fn ($a) => $a->contract()->exists())) {
                $c->tender->update(['status' => 'contracted']);
            }
        });
        $c->creator?->notify(new Notice('Contract signed', "{$c->number} with {$c->supplier->name} is vetted and running.", "/procurement/contracts/{$c->id}", 'file-earmark-check'));
    }

    public function vettingRejected(Contract $c, ?string $comment, bool $withdrawn): void
    {
        $c->update(['status' => $withdrawn ? 'draft' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment]);
    }

    private function active(Contract $c): void
    {
        if ($c->status !== 'active') {
            $this->fail('contract', 'The contract is not running.');
        }
    }

    public function variation(Contract $c, array $data, User $by): ContractVariation
    {
        $this->active($c);
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($data['kind'] === 'extension' && empty($data['new_end_date'])) {
            $this->fail('new_end_date', 'Give the new end date.');
        }
        if ($data['kind'] === 'addendum') {
            if ($amount == 0) {
                $this->fail('amount', 'Give the change in price (minus for a reduction).');
            }
            if ($amount > 0 && empty($data['budget_item_id'])) {
                $this->fail('budget_item_id', 'Choose the budget line that pays for the increase.');
            }
            $limit = ProcurementSetting::current()->variation_limit_percent;
            $increases = (float) $c->variations()->whereIn('status', ['pending', 'approved'])->where('amount', '>', 0)->sum('amount') + max(0, $amount);
            if (round($increases, 2) > round($c->value * $limit / 100, 2)) {
                $this->fail('amount', "Increases would reach ".number_format($increases, 2)."; the limit is {$limit}% of the contract (".number_format($c->value * $limit / 100, 2).'). A larger change needs a new procurement.');
            }
            if ($amount > 0) {
                $item = BudgetItem::find($data['budget_item_id']);
                $check = $item ? $this->budgets->check($item, $amount) : ['over' => true, 'available' => 0];
                if ($check['over'] && $this->budgets->mode() === 'block') {
                    $this->fail('amount', 'The budget line has only '.number_format($check['available'], 2).' available.');
                }
            }
        }

        return DB::transaction(function () use ($c, $data, $by, $amount) {
            $v = ContractVariation::create(['number' => Numbering::next(ContractVariation::class, 'VAR'), 'contract_id' => $c->id, 'kind' => $data['kind'], 'amount' => $data['kind'] === 'addendum' ? $amount : 0,
                'budget_item_id' => $data['budget_item_id'] ?? null, 'new_end_date' => $data['new_end_date'] ?? null, 'reason' => $data['reason'], 'status' => 'pending', 'created_by' => $by->id]);
            if (! $this->workflow->start(ContractVariationProcess::KEY, $v, $by)) {
                $this->variationApproved($v);
            }

            return $v->refresh();
        });
    }

    public function variationApproved(ContractVariation $v): void
    {
        DB::transaction(function () use ($v) {
            $c = $v->contract;
            if ($v->amount > 0) {
                $this->budgets->record($v->budget_item_id, 'committed', $v->amount, $c, "Variation {$v->number}", $v->creator);
            } elseif ($v->amount < 0) {
                foreach ($c->lines()->get()->groupBy('budget_item_id') as $itemId => $g) {
                    $take = min(-$v->amount, $this->budgets->heldBy($c, (int) $itemId));
                    if ($take > 0) {
                        $this->budgets->record((int) $itemId, 'committed', -$take, $c, "Variation {$v->number}", $v->creator);
                        break;
                    }
                }
            }
            if ($v->new_end_date) {
                $c->update(['end_date' => $v->new_end_date, 'warned_on' => null]);
            }
            $v->update(['status' => 'approved', 'decided_at' => now()]);
        });
    }

    public function variationRejected(ContractVariation $v, ?string $comment, bool $withdrawn): void
    {
        $v->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $comment, 'decided_at' => now()]);
    }

    /** A call-off order (goods) at the contract's prices. */
    public function order(Contract $c, array $data, User $by): PurchaseOrder
    {
        $this->active($c);
        if ($c->byCertificate()) {
            $this->fail('contract', 'Works, services and consultancy are paid by certificate, not by order.');
        }
        $lines = $c->lines()->get()->keyBy('id');
        $rows = [];
        foreach ($data['lines'] as $i => $l) {
            $line = $lines[$l['contract_line_id']] ?? null;
            $qty = round((float) $l['quantity'], 4);
            if (! $line || $qty <= 0 || $qty > $line->remaining() + 0.0001) {
                $this->fail("lines.$i.quantity", 'Order at most what is left on the contract line.');
            }
            $rows[] = ['contract_line_id' => $line->id, 'purchase_requisition_line_id' => null, 'item_id' => $line->item_id, 'description' => $line->description, 'unit_id' => $line->unit_id,
                'quantity' => $qty, 'unit_price' => $line->unit_price, 'amount' => round($qty * $line->unit_price, 2), 'budget_item_id' => $line->budget_item_id];
        }
        $total = round(array_sum(array_column($rows, 'amount')), 2);
        if ($total > $c->figures()['balance'] + 0.01) {
            $this->fail('lines', 'This order is more than the contract balance.');
        }

        return DB::transaction(function () use ($c, $data, $by, $rows, $total) {
            $po = PurchaseOrder::create(['number' => Numbering::next(PurchaseOrder::class, 'PO'), 'supplier_id' => $c->supplier_id, 'contract_id' => $c->id, 'store_id' => $data['store_id'] ?? $c->requisition?->store_id,
                'method' => 'contract', 'order_date' => $data['order_date'] ?? today(), 'delivery_date' => $data['delivery_date'] ?? null, 'delivery_place' => $c->requisition?->delivery_place,
                'payment_terms_days' => $c->supplier->payment_terms_days, 'total' => $total, 'status' => 'draft', 'created_by' => $by->id]);
            foreach ($rows as $row) {
                $po->lines()->create($row);
            }

            return $po->refresh();
        });
    }

    /** Called when a call-off order is approved: its amount moves from the contract's commitment to the order's. */
    public function ordered(PurchaseOrder $po, User $by): void
    {
        $c = $po->contract;
        foreach ($po->lines()->get() as $l) {
            $line = $c->lines()->find($l->contract_line_id);
            $line?->update(['ordered_quantity' => round($line->ordered_quantity + $l->quantity, 4)]);
            $take = min($l->amount, $this->budgets->heldBy($c, $l->budget_item_id));
            if ($take > 0) {
                $this->budgets->record($l->budget_item_id, 'committed', -$take, $c, "Ordered on {$po->number}", $by);
            }
        }
    }

    /** A cancelled call-off order gives its quantity and commitment back to the contract. */
    public function orderCancelled(PurchaseOrder $po, User $by): void
    {
        $c = $po->contract;
        foreach ($po->lines()->get() as $l) {
            $line = $c->lines()->find($l->contract_line_id);
            $line?->update(['ordered_quantity' => max(0, round($line->ordered_quantity - $l->quantity, 4))]);
            $this->budgets->record($l->budget_item_id, 'committed', $l->amount, $c, "Order {$po->number} cancelled", $by);
        }
    }

    public function certificate(Contract $c, array $data, ?UploadedFile $file, User $by): ContractCertificate
    {
        $this->active($c);
        if (! $c->byCertificate()) {
            $this->fail('contract', 'Goods contracts are paid by delivery: make a call-off order.');
        }
        $s = ProcurementSetting::current();
        $gross = round((float) $data['gross'], 2);
        $retention = round($gross * $c->retention_percent / 100, 2);
        $daysLate = (int) ($data['days_late'] ?? 0);
        $capLeft = max(0, round($c->value * $c->damages_cap_percent / 100 - (float) $c->certificates()->where('status', 'approved')->sum('damages'), 2));
        $damages = min($capLeft, round($c->value * $c->damages_percent_per_day / 100 * $daysLate, 2));
        $recovery = round((float) ($data['advance_recovery'] ?? 0), 2);
        $net = round($gross - $retention - $damages - $recovery, 2);
        if ($gross <= 0 || $net < 0) {
            $this->fail('gross', 'The certified amount must cover the deductions.');
        }
        if ($gross > $c->figures()['balance'] + 0.01) {
            $this->fail('gross', 'This certificate is more than the contract balance ('.number_format($c->figures()['balance'], 2).').');
        }
        foreach (['clearing_account_id' => true, 'retention_account_id' => $retention > 0, 'damages_account_id' => $damages > 0, 'advance_account_id' => $recovery > 0] as $field => $needed) {
            if ($needed && ! $s->{$field}) {
                $this->fail('gross', 'Set the '.str_replace(['_account_id', '_'], ['', ' '], $field).' account in Procurement → Settings first.');
            }
        }

        return DB::transaction(function () use ($c, $data, $file, $by, $gross, $retention, $damages, $daysLate, $recovery, $net) {
            $cert = ContractCertificate::create(['number' => Numbering::next(ContractCertificate::class, 'CRT', $data['date']), 'contract_id' => $c->id, 'contract_milestone_id' => $data['contract_milestone_id'] ?? null,
                'date' => $data['date'], 'period' => $data['period'] ?? null, 'percent_complete' => $data['percent_complete'] ?? null, 'gross' => $gross, 'retention' => $retention, 'damages' => $damages,
                'days_late' => $daysLate, 'advance_recovery' => $recovery, 'net' => $net, 'description' => $data['description'] ?? null, 'status' => 'pending', 'created_by' => $by->id,
                'path' => $file?->store('contract-certificates', 'local'), 'file_name' => $file?->getClientOriginalName()]);
            if (! $this->workflow->start(ContractCertificateProcess::KEY, $cert, $by)) {
                $this->certificateApproved($cert);
            }

            return $cert->refresh();
        });
    }

    /** Approved certificate: the work is expensed (or capitalised) on its budget lines and one supplier bill is raised for the net. */
    public function certificateApproved(ContractCertificate $cert): void
    {
        DB::transaction(function () use ($cert) {
            if ($cert->payment_request_id) {
                return;
            }
            $c = $cert->contract()->with(['lines.budgetItem', 'supplier.bankAccounts'])->first();
            $s = ProcurementSetting::current();
            $by = $cert->creator;
            $byItem = $c->lines->groupBy('budget_item_id')->map(fn ($g) => $g->sum('amount'));
            $total = max($byItem->sum(), 0.01);
            $journal = [];
            $left = $cert->gross;
            $keys = $byItem->keys()->values();
            foreach ($keys as $i => $itemId) {
                $share = $i === $keys->count() - 1 ? round($left, 2) : round($cert->gross * $byItem[$itemId] / $total, 2);
                $left = round($left - $share, 2);
                $item = $c->lines->firstWhere('budget_item_id', $itemId)->budgetItem;
                $journal[] = ['account_id' => $item->account_id, 'debit' => $share, 'description' => "{$cert->number} {$c->number}", 'department_id' => $item->department_id,
                    'fund_source_id' => $item->fund_source_id, 'task_activity_id' => $item->task_activity_id, 'budget_item_id' => (int) $itemId];
                $take = min($share, $this->budgets->heldBy($c, (int) $itemId));
                if ($take > 0) {
                    $this->budgets->record((int) $itemId, 'committed', -$take, $c, "Certified {$cert->number}", $by);
                }
            }
            $journal[] = ['account_id' => $s->clearing_account_id, 'credit' => $cert->net, 'description' => "Certified not yet paid: {$c->supplier->name}"];
            foreach (['retention' => $s->retention_account_id, 'damages' => $s->damages_account_id, 'advance_recovery' => $s->advance_account_id] as $field => $account) {
                if ($cert->{$field} > 0) {
                    $journal[] = ['account_id' => $account, 'credit' => $cert->{$field}, 'description' => ucfirst(str_replace('_', ' ', $field))." {$c->number}"];
                }
            }
            $entry = $this->posting->post($cert->date->toDateString(), "{$cert->number} {$c->number} {$c->supplier->name}", $journal, $by, 'goods_receipt', $cert);
            $bill = $cert->net > 0 ? $this->bill($c, "Certificate {$cert->number} ({$c->number})", $cert->net, $s->clearing_account_id, (int) $keys->first(), $cert->creator, $cert) : null;
            $cert->update(['status' => 'approved', 'journal_entry_id' => $entry->id, 'payment_request_id' => $bill?->id, 'decided_at' => now()]);
            if ($cert->percent_complete !== null) {
                $c->update(['percent_complete' => $cert->percent_complete]);
            }
            if ($cert->contract_milestone_id) {
                ContractMilestone::whereKey($cert->contract_milestone_id)->update(['status' => 'requested', 'payment_request_id' => $bill?->id]);
            }
        });
    }

    public function certificateRejected(ContractCertificate $cert, ?string $comment, bool $withdrawn): void
    {
        $cert->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $comment, 'decided_at' => now()]);
    }

    private function bill(Contract $c, string $title, float $amount, int $account, ?int $budgetItemId, ?User $by, $source): PaymentRequest
    {
        $supplier = $c->supplier;
        $bank = $supplier->bankAccounts->where('status', 'approved')->sortByDesc('is_default')->first();
        $item = $budgetItemId ? BudgetItem::find($budgetItemId) : null;
        $r = PaymentRequest::create(['number' => Numbering::next(PaymentRequest::class, 'PR'), 'budget_id' => $item?->budget_id, 'department_id' => $c->requisition?->department_id ?? $item?->department_id,
            'title' => $title, 'purpose' => "Contract {$c->number}: {$c->title}", 'total' => $amount, 'status' => 'approved', 'requested_by' => $by?->id ?? $c->created_by,
            'submitted_at' => now(), 'decided_at' => now(), 'source_type' => $source->getMorphClass(), 'source_id' => $source->getKey(), 'supplier_id' => $supplier->id]);
        $r->lines()->create(['budget_item_id' => $budgetItemId, 'account_id' => $account, 'description' => $title, 'amount' => $amount]);
        $r->payees()->create(['name' => $supplier->name, 'kind' => 'supplier', 'method' => $bank ? 'bank' : 'cheque', 'bank_name' => $bank?->bank_name, 'account_name' => $bank?->account_name,
            'account_number' => $bank?->account_number, 'amount' => $amount]);

        return $r;
    }

    /** Advance payment or retention release as a supplier bill. */
    public function payMilestone(ContractMilestone $m, User $by): ContractMilestone
    {
        $c = $m->contract()->with('supplier.bankAccounts', 'requisition')->first();
        $this->active($c);
        if ($m->payment_request_id || ! in_array($m->kind, ['advance', 'retention'], true)) {
            $this->fail('milestone', 'Only an advance or a retention release not yet requested is paid from here; deliveries and certificates pay themselves.');
        }
        $s = ProcurementSetting::current();
        $account = $m->kind === 'advance' ? $s->advance_account_id : $s->retention_account_id;
        if (! $account) {
            $this->fail('milestone', 'Set the '.($m->kind === 'advance' ? 'advance' : 'retention').' account in Procurement → Settings first.');
        }
        if ($m->kind === 'retention' && $m->amount > $c->figures()['retained'] + 0.01) {
            $this->fail('milestone', 'Only '.number_format($c->figures()['retained'], 2).' is held as retention.');
        }
        DB::transaction(function () use ($m, $c, $account, $by) {
            $bill = $this->bill($c, ContractMilestone::KINDS[$m->kind]." {$c->number}", $m->amount, $account, null, $by, $m);
            $m->update(['status' => 'requested', 'payment_request_id' => $bill->id]);
        });

        return $m->refresh();
    }

    public function progress(Contract $c, array $data, ?UploadedFile $file, User $by): void
    {
        $this->active($c);
        $c->progress()->create(['date' => $data['date'], 'kind' => $data['kind'], 'percent_complete' => $data['percent_complete'] ?? null, 'description' => $data['description'], 'created_by' => $by->id,
            'path' => $file?->store('contract-progress', 'local'), 'file_name' => $file?->getClientOriginalName()]);
        if (isset($data['percent_complete'])) {
            $c->update(['percent_complete' => $data['percent_complete']]);
        }
    }

    /** Closed when everything is delivered or certified and paid, and retention is released; terminated early with a reason. Any commitment left is released. */
    public function close(Contract $c, string $note, bool $terminate, User $by): Contract
    {
        $this->active($c);
        if (! $terminate) {
            $f = $c->figures();
            $open = $c->byCertificate()
                ? $c->certificates()->where('status', 'pending')->exists()
                : $c->orders()->whereNotIn('status', ['received', 'closed', 'cancelled', 'rejected', 'draft'])->exists();
            if ($open) {
                $this->fail('contract', 'Orders or certificates are still open.');
            }
            if ($f['retained'] > 0.01) {
                $this->fail('contract', 'Release the retention ('.number_format($f['retained'], 2).') first.');
            }
        }
        DB::transaction(function () use ($c, $note, $terminate, $by) {
            $this->budgets->release($c, $by);
            $c->update(['status' => $terminate ? 'terminated' : 'closed', 'close_note' => $note]);
        });

        return $c->refresh();
    }

    public function withdrawItem($subject, User $by)
    {
        if ($subject->status !== ($subject instanceof Contract ? 'vetting' : 'pending') || $subject->created_by !== $by->id) {
            $this->fail('item', 'Only the person who submitted it can withdraw it while it waits.');
        }
        $instance = $subject->workflow()->where('status', WorkflowInstance::PENDING)->first();
        if ($instance) {
            $this->workflow->cancel($instance, $instance->initiator ?? $by);
        } else {
            match (true) {
                $subject instanceof Contract => $this->vettingRejected($subject, null, true),
                $subject instanceof ContractVariation => $this->variationRejected($subject, null, true),
                default => $this->certificateRejected($subject, null, true),
            };
        }

        return $subject->refresh();
    }

    /** Daily: warn the procurement team before contracts end and securities expire. */
    public function warn(): int
    {
        $days = ProcurementSetting::current()->expiry_warning_days ?: 30;
        $limit = today()->addDays($days);
        $n = 0;
        $officers = \App\Models\User::where('company_id', app(\App\Services\CompanyContext::class)->id())->where('is_active', true)->get()->filter(fn ($u) => $u->can('procurement.manage') || $u->can('procurement.orders'));
        foreach (Contract::with('supplier')->where('status', 'active')->whereNull('warned_on')->where(fn ($q) => $q->whereDate('end_date', '<=', $limit)->orWhereDate('security_expiry', '<=', $limit))->get() as $c) {
            $what = collect([$c->end_date->lte($limit) ? 'ends on '.$c->end_date->format('d M Y') : null, $c->security_expiry?->lte($limit) ? 'security expires on '.$c->security_expiry->format('d M Y') : null])->filter()->join(' and ');
            foreach ($officers as $u) {
                $u->notify(new Notice('Contract expiring', "{$c->number} with {$c->supplier->name} {$what}.", "/procurement/contracts/{$c->id}", 'hourglass-split'));
            }
            $c->update(['warned_on' => today()]);
            $n++;
        }

        return $n;
    }
}
