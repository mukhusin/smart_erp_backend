<?php

namespace Modules\Inventory\Workflow;

class StockAdjustmentProcess extends StockDocumentProcess
{
    public const KEY = 'stock-adjustment';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Stock count adjustment';
    }

    public function description(): string
    {
        return 'Differences found in a stock count, corrected in the ledger and the books.';
    }
}
