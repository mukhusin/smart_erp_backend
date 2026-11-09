<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Services\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every model that a company owns (has a company_id column).
 *
 *  - Queries only see the current company's rows (CompanyScope).
 *  - New rows get the current company's id automatically.
 *
 * Child rows (contract lines, payslip lines...) have no company_id: they belong to a parent
 * that has one, and are always reached through that parent.
 *
 * Cross-company access (platform admin, scheduled jobs) must be explicit:
 *   Employee::withoutCompanyScope()->...  or  CompanyContext::run($company, fn () => ...)
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope());

        static::creating(function ($model) {
            $model->company_id ??= app(CompanyContext::class)->id();
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function withoutCompanyScope(): Builder
    {
        return static::withoutGlobalScope(CompanyScope::class);
    }
}
