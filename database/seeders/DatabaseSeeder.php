<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\ErpModule;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Models\LoginSlide;
use Modules\Platform\Actions\RegisterCompany;
use Modules\Platform\Actions\SendCompanyInvite;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RolePermissionSeeder::class, ErpModuleSeeder::class]);

        // System owner: manages client companies, has no company.
        User::firstOrCreate(['username' => 'admin'], [
            'email' => 'admin@smart-erp.local',
            'password' => Hash::make('password'),
            'is_platform_admin' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // Demo client company, registered the same way the platform admin does it.
        if (! Company::where('name', 'Demo Hospital')->exists()) {
            $company = app(RegisterCompany::class)->handle([
                'company' => ['name' => 'Demo Hospital', 'short_name' => 'DEMO', 'country' => 'Tanzania', 'currency' => 'TZS'],
                'branches' => [
                    ['name' => 'Head Office', 'code' => 'HQ', 'city' => 'Dar es Salaam', 'is_headquarters' => true],
                    ['name' => 'Arusha Branch', 'code' => 'ARU', 'city' => 'Arusha'],
                ],
                'modules' => ErpModule::where('is_core', false)->pluck('slug')->map(fn ($slug) => ['slug' => $slug])->all(),
                'admin' => [
                    'fname' => 'Asha', 'lname' => 'Juma', 'gender' => 'Female',
                    'email' => 'hr@demo.local', 'username' => 'hr', 'employee_no' => 'ST1',
                ],
            ]);

            LoginSlide::create([
                'company_id' => $company->id, 'title' => 'Attendance reminder', 'sort_order' => 1,
                'content' => "Please check in and check out every day. Accurate attendance is needed for payroll.\n\nIf you have a problem marking attendance, contact the HR office.",
            ]);

            // Local development only: give the demo HR admin a known password instead of the invite email.
            app(SendCompanyInvite::class)->companyAdmin($company)
                ->forceFill(['password' => Hash::make('password'), 'email_verified_at' => now()])->save();
        }

        $this->call(DemoOrganizationSeeder::class);

        LoginSlide::firstOrCreate(['company_id' => null, 'title' => 'Welcome to Smart ERP'], [
            'content' => 'HR, payroll, finance and operations in one place.', 'sort_order' => 0,
        ]);

        $this->command->info('Platform admin: admin / password    Demo Hospital HR admin: hr / password (login link: /login?org=demo)');
    }
}
