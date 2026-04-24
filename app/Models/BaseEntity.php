<?php

namespace App\Models;

use App\Models\Scopes\CompanyScope;
use App\Services\CompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * BaseEntity — base class for all company-scoped ERP entities.
 *
 * Every model that belongs to a specific company (i.e. has a company_id column)
 * should extend this class instead of Model. It provides two automatic behaviours:
 *
 *   1. Global scope (CompanyScope): every SELECT query is automatically filtered
 *      to the current authenticated user's company. No controller needs to add
 *      ->where('company_id', ...) manually, though existing ones are harmless.
 *
 *   2. Auto-fill on create: company_id is set from CompanyContext so controllers
 *      never have to pass it in store() / create() calls.
 *
 * Escape hatch — to query across all companies (admin/reporting):
 *   Model::withoutGlobalScope(CompanyScope::class)->get();
 *
 * Models that should NOT extend BaseEntity:
 *   User, Company, CompanyBranch, CompanyModule, ErpModule
 *   and all child/line-item models that have no company_id column.
 */
abstract class BaseEntity extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new CompanyScope());

        static::creating(function (self $model) {
            if (!app()->runningInConsole() && Auth::check()) {
                $model->company_id ??= app(CompanyContext::class)->id();
            }
        });
    }
}
