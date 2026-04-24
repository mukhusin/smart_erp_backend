<?php

namespace App\Models\Scopes;

use App\Services\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Skip in console (seeders, artisan commands) — they manage their own scoping
        if (app()->runningInConsole()) {
            return;
        }

        if (!Auth::check()) {
            return;
        }

        $companyId = app(CompanyContext::class)->id();

        if ($companyId) {
            $builder->where($model->getTable() . '.company_id', $companyId);
        }
    }
}
