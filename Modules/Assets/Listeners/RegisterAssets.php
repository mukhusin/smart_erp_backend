<?php

namespace Modules\Assets\Listeners;

use Modules\Assets\Actions\ManageAssets;
use Modules\Procurement\Events\GoodsAccepted;

class RegisterAssets
{
    public function __construct(private readonly ManageAssets $assets) {}

    public function handle(GoodsAccepted $event): void
    {
        $this->assets->fromGoodsReceipt($event->receipt);
    }
}
