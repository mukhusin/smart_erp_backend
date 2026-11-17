<?php

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Database\Migrations\Migration;
use Modules\Core\Support\DefaultRoles;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Company admins keep administration only (no business data); auditor roles are added to every company. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Company::query()->exists()) {
            return;
        }
        DefaultRoles::syncPermissions();
        foreach (Company::pluck('id') as $companyId) {
            app(CompanyContext::class)->run($companyId, function () use ($companyId) {
                setPermissionsTeamId($companyId);
                foreach ([DefaultRoles::COMPANY_ADMIN, 'internal-auditor', 'external-auditor'] as $name) {
                    $role = Role::where('name', $name)->where('company_id', $companyId)->first() ?? Role::findOrCreate($name, 'web');
                    $role->forceFill(['is_system' => true])->save();
                    $role->syncPermissions(DefaultRoles::expand(DefaultRoles::ROLES[$name]));
                }
            });
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
