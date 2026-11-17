<?php

use App\Providers\AppServiceProvider;
use Modules\Accounting\AccountingServiceProvider;
use Modules\Budgeting\BudgetingServiceProvider;
use Modules\Assets\AssetsServiceProvider;
use Modules\Inventory\InventoryServiceProvider;
use Modules\Procurement\ProcurementServiceProvider;

return [
    AppServiceProvider::class,
    AccountingServiceProvider::class,
    BudgetingServiceProvider::class,
    InventoryServiceProvider::class,
    AssetsServiceProvider::class,
    ProcurementServiceProvider::class,
    \Modules\Audit\AuditServiceProvider::class,
    \Modules\Payments\PaymentsServiceProvider::class,
];
