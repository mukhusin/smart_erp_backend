<?php

namespace Modules\Admin\Http\Controllers\Api\Concerns;

use App\Services\CompanyContext;
use Illuminate\Database\Eloquent\Model;

/** Route-bound records that are not company-scoped by trait (users, roles) must belong to the admin's company. */
trait InCompany
{
    protected function ensureInCompany(Model $model): void
    {
        abort_unless($model->getAttribute('company_id') === app(CompanyContext::class)->id(), 404);
    }
}
