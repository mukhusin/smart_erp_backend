<?php

namespace Modules\Inventory\Workflow;

class StockIssueProcess extends StockDocumentProcess
{
    public const KEY = 'stock-issue';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Issue note';
    }

    public function description(): string
    {
        return 'Items issued from a store to a department. Usually issued straight after the store request is approved; add steps only if issues need a second check.';
    }
}
