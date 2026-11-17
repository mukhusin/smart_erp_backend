<?php

use App\Providers\AppServiceProvider;
use Modules\Accounting\AccountingServiceProvider;
use Modules\Budgeting\BudgetingServiceProvider;
use Modules\Inventory\InventoryServiceProvider;

return [
    AppServiceProvider::class,
    AccountingServiceProvider::class,
    BudgetingServiceProvider::class,
    InventoryServiceProvider::class,
];
