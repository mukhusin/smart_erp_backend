<?php

namespace Modules\Procurement\Events;

use Modules\Procurement\Models\GoodsReceipt;

/** Goods passed inspection; stores and assets take them in from here (one GRN = one stock entry or asset set). */
class GoodsAccepted
{
    public function __construct(public readonly GoodsReceipt $receipt) {}
}
