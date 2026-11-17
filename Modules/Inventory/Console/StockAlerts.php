<?php

namespace Modules\Inventory\Console;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Console\Command;
use Modules\Core\Notifications\Notice;
use Modules\Inventory\Models\StockBalance;
use Modules\Inventory\Models\StockLayer;
use Modules\Inventory\Models\Store;
use Modules\Inventory\Models\InventorySetting;

/** Daily: tell each store's managers about items at or below reorder level and stock expiring soon or expired. */
class StockAlerts extends Command
{
    protected $signature = 'inventory:alerts';

    protected $description = 'Notify store managers about reorder levels and expiring stock';

    public function handle(CompanyContext $context): int
    {
        foreach (Company::query()->pluck('id') as $companyId) {
            $context->run($companyId, function () {
                $days = InventorySetting::current()->expiry_warning_days;
                foreach (Store::with('users')->where('is_active', true)->where('kind', '!=', 'transit')->get() as $store) {
                    $low = StockBalance::with(['item.levels'])->where('store_id', $store->id)->get()->filter(function ($b) use ($store) {
                        $level = $b->item?->levels->firstWhere('store_id', $store->id)?->reorder_level ?? $b->item?->reorder_level;

                        return $level !== null && $b->item->is_active && $b->quantity <= $level;
                    })->count();
                    $expiring = StockLayer::where('store_id', $store->id)->where('quantity_left', '>', 0)->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', today()->addDays($days))->count();
                    if (! $low && ! $expiring) {
                        continue;
                    }
                    foreach ($store->users->where('pivot.role', 'manager') as $user) {
                        $user->notify(new Notice("Stock alerts: {$store->name}", trim(($low ? "{$low} item(s) at or below reorder level. " : '').($expiring ? "{$expiring} batch(es) expiring within {$days} days or expired." : '')), '/inventory/stock', 'boxes'));
                    }
                }
            });
        }

        return self::SUCCESS;
    }
}
