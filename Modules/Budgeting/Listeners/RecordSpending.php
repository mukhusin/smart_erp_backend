<?php

namespace Modules\Budgeting\Listeners;

use Modules\Accounting\Events\JournalPosted;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Budgeting\Services\BudgetService;

class RecordSpending
{
    public function __construct(private readonly BudgetService $budgets) {}

    public function handle(JournalPosted $event): void
    {
        $lines = $event->entry->lines->whereNotNull('budget_item_id');
        if ($lines->isEmpty()) {
            return;
        }
        $items = BudgetItem::with('account')->whereIn('id', $lines->pluck('budget_item_id'))->get()->keyBy('id');
        $source = $event->entry->entry_type === 'reversal' ? null : $event->entry->source;
        foreach ($lines->groupBy('budget_item_id') as $itemId => $group) {
            $item = $items[$itemId] ?? null;
            if (! $item) {
                continue;
            }
            $amount = $item->account?->type === 'revenue' ? $group->sum('credit') - $group->sum('debit') : $group->sum('debit') - $group->sum('credit');
            $this->budgets->spend((int) $itemId, (float) $amount, $source, $event->entry, $event->by);
        }
    }
}
