<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Support\DefaultRoles;
use Modules\Core\Support\PermissionCatalog;
use Spatie\Permission\Models\Permission;

class SyncPermissions extends Command
{
    protected $signature = 'erp:sync-permissions {--prune : Delete permissions that are no longer in the catalog}';

    protected $description = 'Load the permission catalog (grouped by module) into the database';

    public function handle(): int
    {
        DefaultRoles::syncPermissions();
        $this->info('Permissions in catalog: ' . count(PermissionCatalog::names()));

        $stale = Permission::whereNotIn('name', PermissionCatalog::names())->pluck('name');
        if ($stale->isNotEmpty()) {
            if ($this->option('prune')) {
                Permission::whereIn('name', $stale)->delete();
                $this->warn('Deleted: ' . $stale->implode(', '));
            } else {
                $this->warn('Not in catalog (use --prune to delete): ' . $stale->implode(', '));
            }
        }

        return self::SUCCESS;
    }
}
