<?php

namespace Modules\Inventory;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Inventory\Console\StockAlerts;
use Modules\Inventory\Listeners\ReceiveGoods;
use Modules\Procurement\Events\GoodsAccepted;

class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(GoodsAccepted::class, ReceiveGoods::class);
        \Illuminate\Support\Facades\Route::model('storeRequest', \Modules\Inventory\Models\StoreRequisition::class);
        if ($this->app->runningInConsole()) {
            $this->commands([StockAlerts::class]);
        }
    }
}
