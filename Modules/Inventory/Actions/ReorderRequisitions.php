<?php

namespace Modules\Inventory\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Core\Notifications\Notice;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\Store;
use Modules\Procurement\Actions\ManageRequisitions;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\PurchaseRequisitionLine;

/** Prepares a draft purchase requisition per store for items at or below reorder level; a manager reviews and submits it. */
class ReorderRequisitions
{
    public function __construct(private readonly ManageRequisitions $requisitions) {}

    /** @return int requisitions prepared */
    public function run(): int
    {
        $budget = Budget::where('status', 'approved')->whereHas('fiscalYear', fn ($q) => $q->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today()))->first();
        if (! $budget) {
            return 0;
        }
        $made = 0;
        foreach (Store::with('users')->where('is_active', true)->where('kind', '!=', 'transit')->whereNotNull('department_id')->get() as $store) {
            $manager = $store->users->where('pivot.role', 'manager')->first();
            if (! $manager) {
                continue;
            }
            $open = PurchaseRequisitionLine::whereHas('requisition', fn ($q) => $q->where('store_id', $store->id)->whereIn('status', ['draft', 'pending', 'approved', 'rejected']))
                ->pluck('item_id')->filter()->all();
            $lines = [];
            $skipped = [];
            foreach (StockBalance::with(['item.levels', 'item.category'])->where('store_id', $store->id)->get() as $b) {
                $item = $b->item;
                if (! $item?->is_active || in_array($item->id, $open, true)) {
                    continue;
                }
                $level = $item->levels->firstWhere('store_id', $store->id);
                $reorder = $level?->reorder_level ?? $item->reorder_level;
                if ($reorder === null || $b->quantity > $reorder) {
                    continue;
                }
                $max = $level?->max_level ?? $item->max_level ?? $reorder * 2;
                $qty = max(1, round($max - $b->quantity, 2));
                $account = $item->category?->inventory_account_id ?? $item->category?->expense_account_id;
                $line = $account ? BudgetItem::where('budget_id', $budget->id)->where('department_id', $store->department_id)->where('account_id', $account)->value('id') : null;
                if (! $line) {
                    $skipped[] = $item->name;

                    continue;
                }
                $lines[] = ['item_id' => $item->id, 'description' => $item->name, 'quantity' => $qty, 'estimated_unit_cost' => (float) ($item->last_cost ?: $b->averageCost()), 'budget_item_id' => $line];
            }
            if (! $lines) {
                if ($skipped) {
                    $manager->notify(new Notice("Reorder: {$store->name}", 'No budget line found for '.implode(', ', array_slice($skipped, 0, 5)).'; raise their requisition by hand.', '/inventory/stock', 'cart'));
                }

                continue;
            }
            $r = DB::transaction(fn () => $this->requisitions->save(null, ['department_id' => $store->department_id, 'store_id' => $store->id, 'title' => mb_substr("Reorder for {$store->name}", 0, 200),
                'purpose' => 'Prepared automatically for items at or below their reorder level.', 'kind' => 'normal', 'lines' => $lines], $manager));
            $note = count($lines).' item(s) at or below reorder level.'.($skipped ? ' No budget line for: '.implode(', ', array_slice($skipped, 0, 5)).'.' : '').' Review the quantities and submit.';
            foreach ($store->users->where('pivot.role', 'manager') as $user) {
                $user->notify(new Notice("Draft requisition {$r->number}", $note, "/procurement/requisitions/{$r->id}", 'cart'));
            }
            $made++;
        }

        return $made;
    }
}
