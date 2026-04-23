<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Company
        $company = Company::firstOrCreate(
            ['name' => 'Smart ERP Company'],
            [
                'short_name'    => 'SmartERP',
                'email'         => 'admin@smart-erp.local',
                'country'       => 'Tanzania',
                'currency'      => 'TZS',
                'is_active'     => true,
            ]
        );

        $branch = CompanyBranch::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Head Office'],
            [
                'code'             => 'HQ',
                'is_headquarters'  => true,
                'is_active'        => true,
            ]
        );

        // Super Admin role
        $superAdmin = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        // Admin user
        $admin = User::firstOrCreate(
            ['email' => 'admin@smart-erp.local'],
            [
                'username'          => 'admin',
                'password'          => Hash::make('password'),
                'company_id'        => $company->id,
                'company_branch_id' => $branch->id,
                'isActive'          => true,
            ]
        );
        $admin->assignRole($superAdmin);

        $this->command->info('Seeded: Company, Branch, Super Admin user');
        $this->command->info('Login: admin@smart-erp.local  OR  username: admin  /  password: password');
        $this->call([
            ErpModuleSeeder::class,
        ]);
    }
}
