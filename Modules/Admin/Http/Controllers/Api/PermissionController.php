<?php

namespace Modules\Admin\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ErpModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Modules\Core\Services\AccessService;
use Modules\Core\Support\PermissionCatalog;

/**
 * @tags Admin: Roles
 */
class PermissionController extends Controller
{
    /**
     * Permissions grouped by module.
     *
     * Only modules the company can use. Use these names in roles and direct permissions.
     *
     * @response array{data: list<array{module: string, name: string, icon: string|null, permissions: list<array{name: string, label: string}>}>}
     */
    public function __invoke(AccessService $access): JsonResponse
    {
        $user = request()->user();
        $available = app(\App\Services\ModuleAccessService::class)->allForDashboard()->where('has_access', true)->pluck('slug')->all();
        $modules = ErpModule::whereIn('slug', $available)->orderBy('sort_order')->get()->keyBy('slug');

        $data = collect(PermissionCatalog::MODULES)
            ->filter(fn ($_, $slug) => $modules->has($slug))
            ->map(fn ($permissions, $slug) => [
                'module' => $slug,
                'name' => $modules[$slug]->name,
                'icon' => $modules[$slug]->icon ? Str::after($modules[$slug]->icon, 'bi-') : null,
                'permissions' => collect($permissions)->map(fn ($label, $name) => ['name' => $name, 'label' => $label])->values()->all(),
            ])
            ->sortBy(fn ($m) => $modules[$m['module']]->sort_order)
            ->values()->all();

        return response()->json(['data' => $data]);
    }
}
