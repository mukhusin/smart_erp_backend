<?php

namespace App\Services;

use App\Models\CompanyModule;
use App\Models\ErpModule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Which ERP modules the current company may use: core modules always,
 * others when enabled for the company and not expired. Platform admins have no company modules.
 */
class ModuleAccessService
{
    public function __construct(protected CompanyContext $ctx) {}

    /** Active modules with `has_access` set for the current company. */
    public function allForDashboard(): Collection
    {
        $enabled = $this->enabledSlugs();

        return ErpModule::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (ErpModule $module) use ($enabled) {
                $module->has_access = $this->ctx->id() !== null && ($module->is_core || in_array($module->slug, $enabled, true));

                return $module;
            });
    }

    public function hasAccess(string $slug): bool
    {
        return (bool) $this->allForDashboard()->firstWhere('slug', $slug)?->has_access;
    }

    public function enableModule(int $companyId, string $slug, ?\DateTimeInterface $expiresAt = null): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $companyId, 'module_slug' => $slug],
            ['is_enabled' => true, 'enabled_at' => now(), 'expires_at' => $expiresAt]
        );
        Cache::forget("company_modules_{$companyId}");
    }

    public function disableModule(int $companyId, string $slug): void
    {
        CompanyModule::where('company_id', $companyId)->where('module_slug', $slug)->update(['is_enabled' => false]);
        Cache::forget("company_modules_{$companyId}");
    }

    /** @return list<string> */
    private function enabledSlugs(): array
    {
        $companyId = $this->ctx->id();
        if (! $companyId) {
            return [];
        }

        return Cache::remember("company_modules_{$companyId}", 300, fn () => CompanyModule::where('company_id', $companyId)
            ->where('is_enabled', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('module_slug')
            ->all());
    }
}
