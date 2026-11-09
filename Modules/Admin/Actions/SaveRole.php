<?php

namespace Modules\Admin\Actions;

use App\Services\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\AccessService;
use Modules\Core\Support\DefaultRoles;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Create or update a company role and its permissions. company-admin is fixed (always all permissions). */
class SaveRole
{
    public function __construct(private readonly AccessService $access) {}

    /** @param array{name: string, description?: ?string, permissions: list<string>} $data */
    public function handle(?Role $role, array $data): Role
    {
        if ($role?->name === DefaultRoles::COMPANY_ADMIN) {
            throw ValidationException::withMessages(['role' => 'The company-admin role always has every permission and cannot be changed.']);
        }

        return DB::transaction(function () use ($role, $data) {
            $role ??= new Role(['guard_name' => 'web', 'company_id' => app(CompanyContext::class)->id()]);
            $role->name = $data['name'];
            $role->description = $data['description'] ?? null;
            $role->save();
            $role->syncPermissions($data['permissions']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->access->forget();

            return $role;
        });
    }
}
