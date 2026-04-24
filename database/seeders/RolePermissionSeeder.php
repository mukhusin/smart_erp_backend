<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // Employee management
            'manage-employees', 'manage-contracts', 'manage-departments',
            // Leave
            'manage-leave-types', 'approve-leave-hod', 'approve-leave-hr',
            // Payroll
            'manage-payroll',
            // Roster
            'manage-rosters',
            // Admin
            'manage-users', 'manage-roles', 'manage-modules', 'manage-companies',
            // Reports
            'view-reports',
            // Self-service (every employee)
            'self-service',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $hrOfficer  = Role::firstOrCreate(['name' => 'hr-officer',  'guard_name' => 'web']);
        $hod        = Role::firstOrCreate(['name' => 'hod',         'guard_name' => 'web']);
        $employee   = Role::firstOrCreate(['name' => 'employee',    'guard_name' => 'web']);

        $superAdmin->syncPermissions(Permission::all());

        $hrOfficer->syncPermissions([
            'manage-employees', 'manage-contracts', 'manage-departments',
            'manage-leave-types', 'approve-leave-hod', 'approve-leave-hr',
            'manage-payroll', 'manage-rosters', 'view-reports', 'self-service',
        ]);

        $hod->syncPermissions([
            'approve-leave-hod', 'manage-rosters', 'self-service',
        ]);

        $employee->syncPermissions(['self-service']);

        $this->command->info('Roles seeded: super-admin, hr-officer, hod, employee');
    }
}
