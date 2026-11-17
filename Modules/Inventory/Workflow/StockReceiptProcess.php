<?php

namespace Modules\Inventory\Workflow;

class StockReceiptProcess extends StockDocumentProcess
{
    public const KEY = 'stock-receipt';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Stock received without an order';
    }

    public function description(): string
    {
        return 'Opening balances, donations, found stock and returns taken into a store.';
    }
}
