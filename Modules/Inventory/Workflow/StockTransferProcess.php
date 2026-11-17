<?php

namespace Modules\Inventory\Workflow;

class StockTransferProcess extends StockDocumentProcess
{
    public const KEY = 'stock-transfer';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Transfer between stores';
    }

    public function description(): string
    {
        return 'Stock sent from one store to another through goods in transit.';
    }
}
