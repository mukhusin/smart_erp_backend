<?php

namespace App\Models\Scopes;

use App\Services\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits queries to the current company. Fails closed: in a web/API request with no company
 * (guest or platform admin) it returns nothing, never another company's data.
 * Console code (commands, seeders, jobs) without a company sees all rows and must scope itself,
 * normally with CompanyContext::run().
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $companyId = app(CompanyContext::class)->id();

        if ($companyId) {
            $builder->where($model->qualifyColumn('company_id'), $companyId);
        } elseif (! app()->runningInConsole() || app()->runningUnitTests()) {
            $builder->whereRaw('1 = 0');
        }
    }
}
