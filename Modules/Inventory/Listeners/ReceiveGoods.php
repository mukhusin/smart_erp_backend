<?php

namespace Modules\Inventory\Listeners;

use Modules\Inventory\Services\StockService;
use Modules\Procurement\Events\GoodsAccepted;

/** Accepted store items enter the delivery store's ledger once per goods received note, at the order price per base unit. */
class ReceiveGoods
{
    public function __construct(private readonly StockService $stock) {}

    public function handle(GoodsAccepted $event): void
    {
        $grn = $event->receipt->loadMissing(['lines.orderLine.item.units', 'order']);
        if (! $grn->store_id || \Modules\Inventory\Models\StockMovement::where('source_type', $grn->getMorphClass())->where('source_id', $grn->id)->exists()) {
            return;
        }
        foreach ($grn->lines as $l) {
            $item = $l->orderLine?->item;
            if (! $item || $item->type !== 'store' || $l->accepted_quantity <= 0) {
                continue;
            }
            $base = $item->toBase($l->accepted_quantity, $l->orderLine->unit_id);
            $this->stock->receive($grn->store_id, $item, $base, $base > 0 ? $l->amount / $base : 0, [
                'date' => $grn->received_on->toDateString(), 'type' => 'receipt', 'reference' => $grn->number, 'source' => $grn,
                'batch' => $l->batch_number, 'expiry' => $l->expiry_date?->toDateString(), 'user' => $grn->creator,
            ]);
        }
    }
}
