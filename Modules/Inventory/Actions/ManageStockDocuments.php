<?php

namespace Modules\Inventory\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Services\PostingService;
use Modules\Core\Notifications\Notice;
use Modules\Core\Support\Numbering;
use Modules\Inventory\Models\InventorySetting;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockDocument;
use Modules\Inventory\Models\Store;
use Modules\Inventory\Models\StoreRequisition;
use Modules\Inventory\Services\StockService;
use Modules\Inventory\Workflow\StockDocumentProcess;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

/** Issue notes, transfer notes, stock receipts (opening, donation, found, returned) and stock counts. */
class ManageStockDocuments
{
    public function __construct(private readonly WorkflowEngine $workflow, private readonly StockService $stock, private readonly PostingService $posting) {}

    public function create(string $kind, array $data, User $by): StockDocument
    {
        $store = Store::where('is_active', true)->findOrFail($data['store_id']);
        if ($kind === 'transfer') {
            $to = Store::where('is_active', true)->find($data['to_store_id'] ?? 0);
            if (! $to || $to->id === $store->id || $to->kind === 'transit') {
                throw ValidationException::withMessages(['to_store_id' => 'Choose another store to send to.']);
            }
            $this->transitStore();
        }
        if ($kind === 'receipt' && empty($data['reason'])) {
            throw ValidationException::withMessages(['reason' => 'Say why the stock is received (opening, donation, found or returned).']);
        }
        $requisition = ! empty($data['store_requisition_id']) ? StoreRequisition::with('lines')->find($data['store_requisition_id']) : null;
        if ($requisition && (! in_array($requisition->status, ['approved', 'partial'], true) || $requisition->store_id !== $store->id)) {
            throw ValidationException::withMessages(['store_requisition_id' => 'Issue against an approved request of this store.']);
        }
        $items = Item::with('units')->whereIn('id', array_column($data['lines'] ?? [], 'item_id'))->get()->keyBy('id');

        return DB::transaction(function () use ($kind, $data, $by, $store, $requisition, $items) {
            $doc = StockDocument::create([
                'number' => Numbering::next(StockDocument::class, StockDocument::PREFIX[$kind], $data['date']), 'kind' => $kind, 'reason' => $data['reason'] ?? null, 'store_id' => $store->id,
                'to_store_id' => $data['to_store_id'] ?? null, 'store_requisition_id' => $requisition?->id, 'department_id' => $data['department_id'] ?? $requisition?->department_id,
                'task_activity_id' => $data['task_activity_id'] ?? $requisition?->task_activity_id, 'received_by_user_id' => $data['received_by_user_id'] ?? $requisition?->requested_by,
                'date' => $data['date'], 'notes' => $data['notes'] ?? null, 'status' => $kind === 'count' ? 'counting' : 'draft', 'created_by' => $by->id,
            ]);
            if ($kind === 'count') {
                $this->snapshot($doc, $data);
            } else {
                foreach ($data['lines'] as $i => $l) {
                    $item = $items[$l['item_id']] ?? null;
                    if (! $item || $item->type !== 'store') {
                        throw ValidationException::withMessages(["lines.$i.item_id" => 'Choose a store item.']);
                    }
                    if ($requisition && ! empty($l['store_requisition_line_id']) && ! $requisition->lines->contains('id', (int) $l['store_requisition_line_id'])) {
                        throw ValidationException::withMessages(["lines.$i" => 'A line is not on the request.']);
                    }
                    $base = $item->toBase((float) $l['quantity'], $l['unit_id'] ?? null);
                    $unitCost = $kind === 'receipt' ? round((float) ($l['unit_cost'] ?? 0) / max($base / max((float) $l['quantity'], 0.0001), 0.0001), 4) : 0;
                    $doc->lines()->create(['item_id' => $item->id, 'unit_id' => $l['unit_id'] ?? $item->unit_id, 'store_requisition_line_id' => $l['store_requisition_line_id'] ?? null,
                        'stock_layer_id' => $l['stock_layer_id'] ?? null, 'quantity' => round((float) $l['quantity'], 4), 'base_quantity' => $base, 'unit_cost' => $unitCost,
                        'value' => round($base * $unitCost, 2), 'batch_number' => $l['batch_number'] ?? null, 'expiry_date' => $l['expiry_date'] ?? null, 'reason' => $l['reason'] ?? null]);
                }
            }

            return $doc->refresh();
        });
    }

    /** Count sheet: the quantities the ledger shows at the count date, frozen when the count starts. */
    private function snapshot(StockDocument $doc, array $data): void
    {
        $balances = StockBalance::with('item')->where('store_id', $doc->store_id)
            ->when(! empty($data['item_category_id']), fn ($q) => $q->whereHas('item', fn ($i) => $i->where('item_category_id', $data['item_category_id'])))
            ->where(fn ($q) => $q->where('quantity', '!=', 0)->orWhereHas('item', fn ($i) => $i->where('is_active', true)))->get();
        foreach ($balances as $b) {
            $doc->lines()->create(['item_id' => $b->item_id, 'unit_id' => $b->item?->unit_id, 'quantity' => $b->quantity, 'base_quantity' => 0, 'system_quantity' => $b->quantity,
                'unit_cost' => $b->averageCost(), 'value' => 0]);
        }
    }

    /** @param list<array{id: int, counted: float, reason: ?string}> $counts */
    public function saveCounts(StockDocument $doc, array $counts): StockDocument
    {
        if ($doc->kind !== 'count' || ! in_array($doc->status, ['counting', 'rejected'], true)) {
            throw ValidationException::withMessages(['count' => 'Counts are entered while the count is open.']);
        }
        $lines = $doc->lines->keyBy('id');
        foreach ($counts as $c) {
            $line = $lines[$c['id']] ?? null;
            if ($line) {
                $variance = round((float) $c['counted'] - $line->system_quantity, 4);
                $line->update(['quantity' => round((float) $c['counted'], 4), 'base_quantity' => $variance, 'value' => round($variance * $line->unit_cost, 2), 'reason' => $c['reason'] ?? $line->reason]);
            }
        }
        $doc->update(['value' => round((float) $doc->lines()->sum('value'), 2)]);

        return $doc->refresh();
    }

    public function submit(StockDocument $doc, User $by): StockDocument
    {
        if (! in_array($doc->status, ['draft', 'rejected', 'counting'], true)) {
            throw ValidationException::withMessages(['document' => 'This document is already submitted.']);
        }
        if ($doc->kind === 'count') {
            $missing = $doc->lines->filter(fn ($l) => round($l->base_quantity, 4) != 0 && ! $l->reason);
            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Give a reason for every difference ('.$missing->count().' missing).']);
            }
        }
        if ($doc->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Add at least one item.']);
        }

        return DB::transaction(function () use ($doc, $by) {
            $doc->update(['status' => 'pending', 'decision_comment' => null]);
            if (! $this->workflow->start(StockDocumentProcess::keyFor($doc->kind), $doc, $by)) {
                $this->approved($doc);
            }

            return $doc->refresh();
        });
    }

    public function approved(StockDocument $doc): void
    {
        DB::transaction(function () use ($doc) {
            $doc->load(['lines.item.category', 'store', 'creator', 'requisition.lines']);
            match ($doc->kind) {
                'issue' => $this->postIssue($doc),
                'transfer' => $this->dispatch($doc),
                'receipt' => $this->postReceipt($doc),
                'count' => $this->postCount($doc),
            };
        });
    }

    public function rejected(StockDocument $doc, ?string $comment, bool $withdrawn): void
    {
        $doc->update(['status' => $doc->kind === 'count' ? 'counting' : ($withdrawn ? 'draft' : 'rejected'), 'decision_comment' => $withdrawn ? null : $comment]);
    }

    public function withdraw(StockDocument $doc, User $by): StockDocument
    {
        if ($doc->status !== 'pending' || $doc->created_by !== $by->id) {
            throw ValidationException::withMessages(['document' => 'Only your document waiting for approval can be withdrawn.']);
        }
        $instance = $doc->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($doc, null, true);

        return $doc->refresh();
    }

    public function cancel(StockDocument $doc): void
    {
        if (! in_array($doc->status, ['draft', 'rejected', 'counting'], true)) {
            throw ValidationException::withMessages(['document' => 'Only a draft document can be cancelled.']);
        }
        $doc->update(['status' => 'cancelled']);
    }

    private function postIssue(StockDocument $doc): void
    {
        $by = $doc->creator;
        $journal = [];
        $total = 0.0;
        foreach ($doc->lines as $line) {
            $out = $this->stock->issue($doc->store_id, $line->item, $line->base_quantity, ['date' => $doc->date->toDateString(), 'type' => 'issue', 'reference' => $doc->number,
                'source' => $doc, 'layer_id' => $line->stock_layer_id, 'department_id' => $doc->department_id, 'user' => $by]);
            $line->update(['value' => $out['value'], 'unit_cost' => $line->base_quantity > 0 ? round($out['value'] / $line->base_quantity, 4) : 0,
                'batch_number' => collect($out['layers'])->pluck('batch')->filter()->unique()->join(', ') ?: null]);
            $total += $out['value'];
            [$expense, $inventory] = $this->accounts($line->item);
            if ($out['value'] > 0) {
                $journal[] = ['account_id' => $expense, 'debit' => $out['value'], 'description' => $line->item->name, 'department_id' => $doc->department_id, 'task_activity_id' => $doc->task_activity_id];
                $journal[] = ['account_id' => $inventory, 'credit' => $out['value'], 'description' => $line->item->name];
            }
            if ($line->store_requisition_line_id) {
                $rl = $doc->requisition?->lines->firstWhere('id', $line->store_requisition_line_id);
                $rl?->update(['issued_quantity' => round($rl->issued_quantity + $this->inUnit($line->item, $line->base_quantity, $rl->unit_id), 4)]);
            }
        }
        $entry = $journal ? $this->posting->post($doc->date->toDateString(), "{$doc->number} issued from {$doc->store->name}", $journal, $by, 'stock_issue', $doc) : null;
        $doc->update(['status' => $doc->received_by_user_id ? 'awaiting' : 'posted', 'value' => round($total, 2), 'journal_entry_id' => $entry?->id, 'posted_at' => now()]);
        if ($doc->requisition) {
            $done = $doc->requisition->lines()->get()->every(fn ($l) => $l->remaining() <= 0);
            $doc->requisition->update(['status' => $done ? 'issued' : 'partial']);
        }
        $doc->receiver?->notify(new Notice('Items issued', "{$doc->number}: your items are issued from {$doc->store->name}. Confirm when you receive them.", "/self-service/store-requests", 'box'));
    }

    public function confirm(StockDocument $doc, User $by): StockDocument
    {
        if ($doc->status !== 'awaiting' || $doc->received_by_user_id !== $by->id) {
            throw ValidationException::withMessages(['document' => 'Only the receiver can confirm items awaiting receipt.']);
        }
        $doc->update(['status' => 'received', 'confirmed_at' => now()]);

        return $doc->refresh();
    }

    private function dispatch(StockDocument $doc): void
    {
        $transit = $this->transitStore();
        $total = 0.0;
        foreach ($doc->lines as $line) {
            $out = $this->stock->issue($doc->store_id, $line->item, $line->base_quantity, ['date' => $doc->date->toDateString(), 'type' => 'transfer_out', 'reference' => $doc->number, 'source' => $doc, 'layer_id' => $line->stock_layer_id, 'user' => $doc->creator]);
            foreach ($out['layers'] as $l) {
                $this->stock->receive($transit->id, $line->item, $l['quantity'], $l['unit_cost'], ['date' => $doc->date->toDateString(), 'type' => 'transfer_in', 'reference' => $doc->number,
                    'source' => $doc, 'batch' => $l['batch'], 'expiry' => $l['expiry'], 'user' => $doc->creator]);
            }
            $line->update(['value' => $out['value'], 'unit_cost' => $line->base_quantity > 0 ? round($out['value'] / $line->base_quantity, 4) : 0]);
            $total += $out['value'];
        }
        $doc->update(['status' => 'transit', 'value' => round($total, 2), 'posted_at' => now()]);
    }

    /** @param list<array{id: int, received: float, reason: ?string}> $received */
    public function receiveTransfer(StockDocument $doc, array $received, User $by): StockDocument
    {
        if ($doc->kind !== 'transfer' || $doc->status !== 'transit') {
            throw ValidationException::withMessages(['document' => 'Only a transfer in transit can be received.']);
        }
        $transit = $this->transitStore();
        $lines = $doc->lines()->with('item')->get()->keyBy('id');
        DB::transaction(function () use ($doc, $received, $by, $transit, $lines) {
            foreach ($received as $r) {
                $line = $lines[$r['id']] ?? null;
                if (! $line) {
                    continue;
                }
                $qty = round((float) $r['received'], 4);
                if ($qty > $line->base_quantity) {
                    throw ValidationException::withMessages(['lines' => "{$line->item->name}: received more than was sent."]);
                }
                if ($qty < $line->base_quantity && empty($r['reason'])) {
                    throw ValidationException::withMessages(['lines' => "{$line->item->name}: give the reason for the shortage or damage."]);
                }
                $out = $this->stock->issue($transit->id, $line->item, $qty, ['date' => today()->toDateString(), 'type' => 'transfer_out', 'reference' => $doc->number, 'source' => $doc, 'user' => $by]);
                foreach ($out['layers'] as $l) {
                    $this->stock->receive($doc->to_store_id, $line->item, $l['quantity'], $l['unit_cost'], ['date' => today()->toDateString(), 'type' => 'transfer_in', 'reference' => $doc->number,
                        'source' => $doc, 'batch' => $l['batch'], 'expiry' => $l['expiry'], 'user' => $by]);
                }
                $line->update(['received_quantity' => $qty, 'reason' => $r['reason'] ?? null]);
            }
            $doc->update(['status' => 'received', 'confirmed_at' => now()]);
        });

        return $doc->refresh();
    }

    private function postReceipt(StockDocument $doc): void
    {
        $settings = InventorySetting::current();
        $credit = match ($doc->reason) {
            'opening' => $settings->opening_account_id,
            'donation' => $settings->donation_account_id,
            default => null,
        };
        if (in_array($doc->reason, ['opening', 'donation'], true) && ! $credit) {
            throw ValidationException::withMessages(['settings' => 'Choose the '.($doc->reason === 'opening' ? 'opening balance' : 'donations').' account in store settings first.']);
        }
        $journal = [];
        foreach ($doc->lines as $line) {
            $this->stock->receive($doc->store_id, $line->item, $line->base_quantity, $line->unit_cost, ['date' => $doc->date->toDateString(),
                'type' => ['opening' => 'opening', 'donation' => 'donation', 'return' => 'return'][$doc->reason] ?? 'receipt', 'reference' => $doc->number, 'source' => $doc,
                'batch' => $line->batch_number, 'expiry' => $line->expiry_date?->toDateString(), 'department_id' => $doc->department_id, 'user' => $doc->creator]);
            [$expense, $inventory] = $this->accounts($line->item);
            if ($line->value > 0) {
                $journal[] = ['account_id' => $inventory, 'debit' => $line->value, 'description' => $line->item->name];
                $journal[] = ['account_id' => $credit ?? $expense, 'credit' => $line->value, 'description' => (StockDocument::REASONS[$doc->reason] ?? 'Received').": {$line->item->name}", 'department_id' => $credit ? null : $doc->department_id];
            }
        }
        $entry = $journal ? $this->posting->post($doc->date->toDateString(), "{$doc->number} ".(StockDocument::REASONS[$doc->reason] ?? 'stock received')." into {$doc->store->name}", $journal, $doc->creator, 'stock_receipt', $doc) : null;
        $doc->update(['status' => 'posted', 'value' => round($doc->lines->sum('value'), 2), 'journal_entry_id' => $entry?->id, 'posted_at' => now()]);
    }

    private function postCount(StockDocument $doc): void
    {
        $journal = [];
        foreach ($doc->lines as $line) {
            $variance = round($line->base_quantity, 4);
            if ($variance == 0) {
                continue;
            }
            [$expense, $inventory] = $this->accounts($line->item);
            $meta = ['date' => $doc->date->toDateString(), 'reference' => $doc->number, 'source' => $doc, 'user' => $doc->creator];
            if ($variance > 0) {
                $this->stock->receive($doc->store_id, $line->item, $variance, $line->unit_cost ?: (float) $line->item->last_cost, $meta + ['type' => 'adjustment_in']);
                $value = round($variance * ($line->unit_cost ?: (float) $line->item->last_cost), 2);
                $journal[] = ['account_id' => $inventory, 'debit' => $value, 'description' => "Count gain: {$line->item->name}"];
                $journal[] = ['account_id' => $expense, 'credit' => $value, 'description' => "Count gain: {$line->item->name}"];
            } else {
                $out = $this->stock->issue($doc->store_id, $line->item, -$variance, $meta + ['type' => 'adjustment_out']);
                $value = $out['value'];
                $journal[] = ['account_id' => $expense, 'debit' => $value, 'description' => "Count loss: {$line->item->name} — {$line->reason}"];
                $journal[] = ['account_id' => $inventory, 'credit' => $value, 'description' => "Count loss: {$line->item->name}"];
            }
            $line->update(['value' => $variance > 0 ? $value : -$value]);
        }
        $journal = array_values(array_filter($journal, fn ($l) => ($l['debit'] ?? $l['credit'] ?? 0) > 0));
        $entry = count($journal) >= 2 ? $this->posting->post($doc->date->toDateString(), "{$doc->number} stock count adjustment, {$doc->store->name}", $journal, $doc->creator, 'stock_adjustment', $doc) : null;
        $doc->update(['status' => 'posted', 'value' => round($doc->lines()->sum('value'), 2), 'journal_entry_id' => $entry?->id, 'posted_at' => now()]);
    }

    /** Wrong posted issue or receipt: put the opposite through the ledger and reverse its journal. */
    public function reverse(StockDocument $doc, User $by, string $reason): StockDocument
    {
        if (! in_array($doc->kind, ['issue', 'receipt'], true) || ! in_array($doc->status, ['posted', 'awaiting', 'received'], true)) {
            throw ValidationException::withMessages(['document' => 'Only a posted issue note or stock receipt can be reversed.']);
        }
        DB::transaction(function () use ($doc, $by, $reason) {
            $doc->load(['lines.item', 'requisition.lines']);
            $moves = \Modules\Inventory\Models\StockMovement::with('layer')->where('source_type', $doc->getMorphClass())->where('source_id', $doc->id)->get();
            foreach ($moves as $m) {
                $item = $doc->lines->firstWhere('item_id', $m->item_id)->item;
                $meta = ['date' => today()->toDateString(), 'type' => 'reversal', 'reference' => $doc->number, 'source' => $doc, 'user' => $by];
                if ($m->quantity < 0) {
                    $this->stock->receive($m->store_id, $item, -$m->quantity, $m->unit_cost, $meta + ['batch' => $m->layer?->batch_number, 'expiry' => $m->layer?->expiry_date?->toDateString()]);
                } else {
                    $this->stock->issue($m->store_id, $item, $m->quantity, $meta + ['layer_id' => $m->stock_layer_id]);
                }
            }
            if ($doc->journal) {
                $this->posting->reverse($doc->journal, today()->toDateString(), $by, "{$doc->number} reversed: {$reason}");
            }
            foreach ($doc->lines as $line) {
                if ($line->store_requisition_line_id) {
                    $rl = $doc->requisition?->lines->firstWhere('id', $line->store_requisition_line_id);
                    $rl?->update(['issued_quantity' => max(0, round($rl->issued_quantity - $this->inUnit($line->item, $line->base_quantity, $rl->unit_id), 4))]);
                }
            }
            if ($doc->requisition) {
                $doc->requisition->update(['status' => $doc->requisition->lines()->sum('issued_quantity') > 0 ? 'partial' : 'approved']);
            }
            $doc->update(['status' => 'reversed', 'decision_comment' => $reason]);
        });

        return $doc->refresh();
    }

    /** @return array{0: int, 1: int} expense account, inventory account of the item's category */
    private function accounts(Item $item): array
    {
        $c = $item->category;
        if (! $c?->inventory_account_id || ! $c?->expense_account_id) {
            throw ValidationException::withMessages(['accounts' => "Set the inventory and expense accounts on the category {$c?->name} first."]);
        }

        return [$c->expense_account_id, $c->inventory_account_id];
    }

    private function inUnit(Item $item, float $base, ?int $unitId): float
    {
        $item->loadMissing('units');

        return round($base / max($item->toBase(1, $unitId), 0.0001), 4);
    }

    private function transitStore(): Store
    {
        $store = Store::where('kind', 'transit')->where('is_active', true)->orderBy('id')->first();
        if (! $store) {
            throw ValidationException::withMessages(['to_store_id' => 'Create a “Goods in transit” store first (Stores → New store, kind goods in transit).']);
        }

        return $store;
    }
}
