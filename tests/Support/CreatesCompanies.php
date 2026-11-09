<?php

namespace Tests\Support;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Modules\Platform\Actions\RegisterCompany;
use Modules\Platform\Actions\SendCompanyInvite;

trait CreatesCompanies
{
    /** Registers a company the real way and returns [company, its admin user with password "password"]. */
    protected function createCompany(string $name = 'Acme', array $overrides = []): array
    {
        Mail::fake();
        if (! \App\Models\ErpModule::exists()) {
            (new \Database\Seeders\ErpModuleSeeder())->run();
        }
        $slug = strtolower(preg_replace('/\W+/', '', $name));
        $company = app(RegisterCompany::class)->handle(array_replace_recursive([
            'company' => ['name' => $name],
            'branches' => [['name' => 'Head Office', 'is_headquarters' => true]],
            'modules' => [],
            'admin' => ['fname' => 'Admin', 'lname' => $name, 'gender' => 'Female', 'email' => "hr@{$slug}.test", 'username' => "hr_{$slug}"],
        ], $overrides));

        $admin = app(SendCompanyInvite::class)->companyAdmin($company);
        $admin->forceFill(['password' => Hash::make('password'), 'email_verified_at' => now()])->save();

        return [$company, $admin];
    }

    protected function createPlatformAdmin(): User
    {
        return User::factory()->create(['username' => 'owner', 'is_platform_admin' => true, 'company_id' => null]);
    }
}
