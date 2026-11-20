<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Modules\Core\Support\DefaultRoles;

/** Adds the finance, budget, planning, procurement, stores, fleet, asset and director roles to every company. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Company::query()->exists()) {
            return;
        }
        DefaultRoles::syncPermissions();
        DefaultRoles::addMissingToCompanies();
    }

    public function down(): void {}
};
