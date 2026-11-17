<?php

namespace Modules\Assets;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Assets\Console\AssetJobs;
use Modules\Assets\Listeners\RegisterAssets;
use Modules\Procurement\Events\GoodsAccepted;

class AssetsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(GoodsAccepted::class, RegisterAssets::class);
        if ($this->app->runningInConsole()) {
            $this->commands([AssetJobs::class]);
        }
    }
}
