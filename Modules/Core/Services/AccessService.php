<?php

namespace Modules\Core\Services;

use App\Models\User;
use App\Services\ModuleAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\ModuleLock;
use Modules\Core\Models\RoleHandover;
use Modules\Core\Support\PermissionCatalog;

/**
 * The single answer to "may this user do X?" and "which modules can this user open?".
 *
 * permissions(user) = (direct permissions + permissions of assigned roles + permissions of roles
 *                      handed over to the user right now)
 *                     limited to modules available to the user.
 * modules(user)     = modules enabled for the company (core always)
 *                     minus modules locked for the user's branch
 *                     minus modules locked for the user.
 *
 * Results are memoized per user for the current request.
 */
class AccessService
{
    /** @var array<int, array{modules: list<string>, permissions: list<string>, roles: list<string>}> */
    private array $memo = [];

    public function __construct(private readonly ModuleAccessService $companyModules) {}

    public function can(User $user, string $permission): bool
    {
        return in_array($permission, $this->permissions($user), true);
    }

    public function canUseModule(User $user, string $module): bool
    {
        return in_array($module, $this->modules($user), true);
    }

    /** @return list<string> */
    public function permissions(User $user): array
    {
        return $this->resolve($user)['permissions'];
    }

    /** @return list<string> */
    public function modules(User $user): array
    {
        return $this->resolve($user)['modules'];
    }

    /** Assigned role names plus roles handed over to the user right now. @return list<string> */
    public function roles(User $user): array
    {
        return $this->resolve($user)['roles'];
    }

    /** Active handovers to this user (for showing "acting as" in the UI). */
    public function activeHandovers(User $user): Collection
    {
        if (! $user->company_id) {
            return collect();
        }

        return RoleHandover::withoutCompanyScope()->active()
            ->where('company_id', $user->company_id)
            ->where('to_user_id', $user->id)
            ->with(['role:id,name', 'fromUser'])
            ->get();
    }

    public function forget(?User $user = null): void
    {
        if ($user) {
            unset($this->memo[$user->id]);
        } else {
            $this->memo = [];
        }
    }

    private function resolve(User $user): array
    {
        if (isset($this->memo[$user->id])) {
            return $this->memo[$user->id];
        }

        if (! $user->company_id || ! $user->is_active) {
            return $this->memo[$user->id] = ['modules' => [], 'permissions' => [], 'roles' => []];
        }

        $modules = $this->availableModules($user);

        $assignedRoleIds = DB::table(config('permission.table_names.model_has_roles'))
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->id)
            ->where('company_id', $user->company_id)
            ->pluck('role_id');
        $handoverRoleIds = $this->activeHandovers($user)->pluck('role_id');
        $roleIds = $assignedRoleIds->merge($handoverRoleIds)->unique();

        $fromRoles = DB::table(config('permission.table_names.role_has_permissions') . ' as rp')
            ->join(config('permission.table_names.permissions') . ' as p', 'p.id', '=', 'rp.permission_id')
            ->whereIn('rp.role_id', $roleIds)
            ->pluck('p.name');
        $direct = DB::table(config('permission.table_names.model_has_permissions') . ' as mp')
            ->join(config('permission.table_names.permissions') . ' as p', 'p.id', '=', 'mp.permission_id')
            ->where('mp.model_type', $user->getMorphClass())
            ->where('mp.model_id', $user->id)
            ->where('mp.company_id', $user->company_id)
            ->pluck('p.name');

        $permissions = $fromRoles->merge($direct)->unique()
            ->filter(fn (string $name) => in_array(PermissionCatalog::moduleOf($name), $modules, true))
            ->sort()->values()->all();

        $roles = DB::table(config('permission.table_names.roles'))->whereIn('id', $roleIds)->orderBy('name')->pluck('name')->all();

        return $this->memo[$user->id] = ['modules' => $modules, 'permissions' => $permissions, 'roles' => $roles];
    }

    /** @return list<string> */
    private function availableModules(User $user): array
    {
        $enabled = app(\App\Services\CompanyContext::class)->run($user->company_id, fn () => $this->companyModules
            ->allForDashboard()->where('has_access', true)->pluck('slug')->all());

        $locked = ModuleLock::withoutCompanyScope()
            ->where('company_id', $user->company_id)
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->when($user->company_branch_id, fn ($q) => $q->orWhere('company_branch_id', $user->company_branch_id)))
            ->pluck('module_slug')->all();

        return array_values(array_diff($enabled, $locked));
    }
}
