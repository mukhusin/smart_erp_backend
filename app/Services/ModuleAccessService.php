<?php

namespace App\Services;

use App\Models\CompanyModule;
use App\Models\ErpModule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class ModuleAccessService
{
    public function __construct(protected CompanyContext $ctx) {}

    /** All active modules ordered, annotated with access status for current company. */
    public function allForDashboard(): Collection
    {
        $companyId = $this->ctx->id();
        $isSuperAdmin = Auth::check() && Auth::user()->hasRole('super-admin');

        $enabled = $companyId
            ? Cache::remember("company_modules_{$companyId}", 300, fn() =>
                CompanyModule::where('company_id', $companyId)
                    ->where('is_enabled', true)
                    ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->pluck('module_slug')
                    ->toArray()
              )
            : [];

        return ErpModule::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (ErpModule $module) use ($enabled, $isSuperAdmin) {
                $module->has_access = $isSuperAdmin || $module->is_core || in_array($module->slug, $enabled);
                return $module;
            });
    }

    public function hasAccess(string $slug): bool
    {
        if (Auth::user()?->hasRole('super-admin')) return true;

        $module = ErpModule::where('slug', $slug)->first();
        if (!$module || !$module->is_active) return false;
        if ($module->is_core) return true;

        $companyId = $this->ctx->id();
        if (!$companyId) return false;

        return CompanyModule::where('company_id', $companyId)
            ->where('module_slug', $slug)
            ->where('is_enabled', true)
            ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    public function enableModule(int $companyId, string $slug): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $companyId, 'module_slug' => $slug],
            ['is_enabled' => true, 'enabled_at' => now()]
        );
        Cache::forget("company_modules_{$companyId}");
    }

    public function disableModule(int $companyId, string $slug): void
    {
        CompanyModule::where('company_id', $companyId)->where('module_slug', $slug)
            ->update(['is_enabled' => false]);
        Cache::forget("company_modules_{$companyId}");
    }
}
