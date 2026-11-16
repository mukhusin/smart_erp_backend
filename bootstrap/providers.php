<?php

use App\Providers\AppServiceProvider;
use Modules\Accounting\AccountingServiceProvider;
use Modules\Budgeting\BudgetingServiceProvider;

return [
    AppServiceProvider::class,
    AccountingServiceProvider::class,
    BudgetingServiceProvider::class,
];
