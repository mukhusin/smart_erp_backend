<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Support\DefaultRoles;

/** Global permissions. Roles are created per company when the company is registered. */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        DefaultRoles::syncPermissions();
        $this->command?->info('Permissions seeded: ' . count(\Modules\Core\Support\PermissionCatalog::names()));
    }
}
