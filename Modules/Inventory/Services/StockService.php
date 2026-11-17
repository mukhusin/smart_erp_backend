<?php

namespace Modules\Inventory\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Services\AccountingPolicies;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLayer;
use Modules\Inventory\Models\StockMovement;

/**
 * The store ledger. Every quantity in or out goes through here: one movement per layer touched, the balance row is
 * locked while it changes (no negative or double-counted stock), and issues are costed by the company's policy
 * (FIFO, weighted average, specific identification or LIFO where the framework allows it).
 */
class StockService
{
    public function __construct(private readonly AccountingPolicies $policies) {}

    /**
     * @param  array{date: string, type: string, reference?: ?string, source?: ?Model, batch?: ?string, expiry?: ?string, serial?: ?string, department_id?: ?int, user?: ?User}  $meta
     * @return array{quantity: float, value: float}
     */
    public function receive(int $storeId, Item $item, float $quantity, float $unitCost, array $meta): array
    {
        if ($quantity <= 0) {
            return ['quantity' => 0.0, 'value' => 0.0];
        }

        return DB::transaction(function () use ($storeId, $item, $quantity, $unitCost, $meta) {
            $balance = $this->lockBalance($storeId, $item->id);
            $value = round($quantity * $unitCost, 2);
            $layer = StockLayer::create(['store_id' => $storeId, 'item_id' => $item->id, 'batch_number' => $meta['batch'] ?? null, 'expiry_date' => $meta['expiry'] ?? null,
                'serial' => $meta['serial'] ?? null, 'received_on' => $meta['date'], 'quantity_in' => round($quantity, 4), 'quantity_left' => round($quantity, 4), 'unit_cost' => round($unitCost, 4),
                'source_type' => isset($meta['source']) ? $meta['source']->getMorphClass() : null, 'source_id' => isset($meta['source']) ? $meta['source']->getKey() : null]);
            $balance->update(['quantity' => round($balance->quantity + $quantity, 4), 'value' => round($balance->value + $value, 2)]);
            $this->movement($balance, $layer->id, $quantity, $unitCost, $value, $meta);
            $item->update(['last_cost' => round($unitCost, 4)]);

            return ['quantity' => round($quantity, 4), 'value' => $value];
        });
    }

    /**
     * Take stock out of a store. Throws when the store does not hold enough.
     *
     * @param  array{date: string, type: string, reference?: ?string, source?: ?Model, layer_id?: ?int, department_id?: ?int, user?: ?User}  $meta
     * @return array{quantity: float, value: float, layers: list<array{layer_id: int, quantity: float, unit_cost: float, batch: ?string, expiry: ?string}>}
     */
    public function issue(int $storeId, Item $item, float $quantity, array $meta): array
    {
        if ($quantity <= 0) {
            return ['quantity' => 0.0, 'value' => 0.0, 'layers' => []];
        }

        return DB::transaction(function () use ($storeId, $item, $quantity, $meta) {
            $balance = $this->lockBalance($storeId, $item->id);
            if (round($quantity, 4) > round($balance->quantity, 4)) {
                throw ValidationException::withMessages(['quantity' => "{$item->name}: only ".rtrim(rtrim(number_format($balance->quantity, 4), '0'), '.').' in stock.']);
            }
            $method = $this->policies->policy('inventory_costing', $meta['date']);
            $average = $balance->averageCost();
            $left = round($quantity, 4);
            $taken = [];
            $value = 0.0;
            foreach ($this->layersFor($storeId, $item, $method, $meta['layer_id'] ?? null) as $layer) {
                if ($left <= 0) {
                    break;
                }
                $take = min($left, $layer->quantity_left);
                if ($take <= 0) {
                    continue;
                }
                $layer->update(['quantity_left' => round($layer->quantity_left - $take, 4)]);
                $cost = $method === 'weighted_average' ? $average : $layer->unit_cost;
                $lineValue = round($take * $cost, 2);
                $left = round($left - $take, 4);
                $last = $left <= 0 && round($balance->quantity - $quantity, 4) <= 0;
                if ($last) {
                    $lineValue = round($balance->value - $value, 2);
                }
                $value = round($value + $lineValue, 2);
                $taken[] = ['layer_id' => $layer->id, 'quantity' => $take, 'unit_cost' => $take > 0 ? round($lineValue / $take, 4) : 0.0, 'value' => $lineValue,
                    'batch' => $layer->batch_number, 'expiry' => $layer->expiry_date?->toDateString()];
            }
            if ($left > 0) {
                throw ValidationException::withMessages(['quantity' => "{$item->name}: the chosen batch does not hold enough."]);
            }
            $running = $balance->replicate();
            foreach ($taken as $t) {
                $running->quantity = round($running->quantity - $t['quantity'], 4);
                $running->value = round($running->value - $t['value'], 2);
                $this->movement($running, $t['layer_id'], -$t['quantity'], $t['unit_cost'], -$t['value'], $meta, $balance);
            }
            $balance->update(['quantity' => $running->quantity, 'value' => $running->value] + (($meta['type'] ?? '') === 'issue' ? ['last_issue_on' => $meta['date']] : []));

            return ['quantity' => round($quantity, 4), 'value' => $value, 'layers' => $taken];
        });
    }

    public function balance(int $storeId, int $itemId): StockBalance
    {
        return StockBalance::firstOrNew(['store_id' => $storeId, 'item_id' => $itemId], ['quantity' => 0, 'value' => 0]);
    }

    private function lockBalance(int $storeId, int $itemId): StockBalance
    {
        StockBalance::firstOrCreate(['store_id' => $storeId, 'item_id' => $itemId], ['quantity' => 0, 'value' => 0]);

        return StockBalance::where('store_id', $storeId)->where('item_id', $itemId)->lockForUpdate()->firstOrFail();
    }

    /** Order in which layers are used: a chosen batch first; expiring stock first for expiry-tracked items (FEFO); then by method. */
    private function layersFor(int $storeId, Item $item, string $method, ?int $layerId)
    {
        $q = StockLayer::where('store_id', $storeId)->where('item_id', $item->id)->where('quantity_left', '>', 0)->lockForUpdate();
        if ($layerId) {
            return (clone $q)->whereKey($layerId)->get();
        }
        if ($item->track_expiry) {
            $q->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date');
        }

        return $method === 'lifo' ? $q->orderByDesc('received_on')->orderByDesc('id')->get() : $q->orderBy('received_on')->orderBy('id')->get();
    }

    private function movement(StockBalance $after, ?int $layerId, float $quantity, float $unitCost, float $value, array $meta, ?StockBalance $row = null): void
    {
        $row ??= $after;
        StockMovement::create([
            'store_id' => $row->store_id, 'item_id' => $row->item_id, 'stock_layer_id' => $layerId, 'date' => $meta['date'], 'type' => $meta['type'], 'quantity' => round($quantity, 4),
            'unit_cost' => round($unitCost, 4), 'value' => round($value, 2), 'balance_quantity' => round($after->quantity, 4), 'balance_value' => round($after->value, 2),
            'reference' => $meta['reference'] ?? null, 'source_type' => isset($meta['source']) ? $meta['source']->getMorphClass() : null,
            'source_id' => isset($meta['source']) ? $meta['source']->getKey() : null, 'department_id' => $meta['department_id'] ?? null, 'user_id' => ($meta['user'] ?? null)?->id ?? auth()->id(),
        ]);
    }
}
