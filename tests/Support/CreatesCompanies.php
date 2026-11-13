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

    /** Gives every current employee of the company without an active contract one, so they count as active staff. */
    protected function giveActiveContracts(Company $company): void
    {
        $typeId = \App\Models\NatureOfContract::withoutCompanyScope()->where('company_id', $company->id)->value('id')
            ?? \App\Models\NatureOfContract::withoutCompanyScope()->create(['company_id' => $company->id, 'contract_name' => 'Permanent', 'code' => 'PERM', 'employment_type' => 'full_time', 'is_active' => true])->id;
        \App\Models\Employee::withoutCompanyScope()->where('company_id', $company->id)->where('employment_status', '!=', 'terminated')
            ->whereDoesntHave('contracts', fn ($q) => $q->where('is_active', true))->get()
            ->each(fn ($e) => \App\Models\EmployeeContract::create(['employee_id' => $e->id, 'nature_of_contract_id' => $typeId, 'contract_no' => $e->employee_no,
                'basic_salary' => 500000, 'period' => 12, 'start_date' => '2020-01-01', 'end_date' => '2030-12-31', 'status' => 'active', 'is_active' => true]));
    }

    protected function createPlatformAdmin(): User
    {
        return User::factory()->create(['username' => 'owner', 'is_platform_admin' => true, 'company_id' => null]);
    }
}
