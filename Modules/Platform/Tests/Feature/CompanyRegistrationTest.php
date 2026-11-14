<?php

namespace Modules\Platform\Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Platform\Mail\CompanyInviteMail;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class CompanyRegistrationTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'company' => ['name' => 'Kairuki Hospital', 'short_name' => 'KH', 'tin' => '100-200-300', 'currency' => 'TZS', 'phone' => '+255765919184',
                'email' => 'info@kairukihospital.org', 'address' => 'Mikocheni, Regent Street, P.O. Box 4567', 'city' => 'Dar es Salaam', 'country' => 'Tanzania'],
            'branches' => [
                ['name' => 'Mikocheni', 'code' => 'MK', 'is_headquarters' => true],
                ['name' => 'Bunju', 'code' => 'BJ'],
            ],
            'modules' => [['slug' => 'leave'], ['slug' => 'payroll', 'expires_at' => now()->addYear()->toDateString()]],
            'admin' => ['fname' => 'Hamisi', 'lname' => 'Umbaliche', 'gender' => 'Male', 'email' => 'Hr@Kairuki.test', 'phone_number' => '0700000000'],
        ], $overrides);
    }

    /** Like a new browser request without a session: forget the users the guards remember. */
    private function startNewRequestAsGuest(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ErpModuleSeeder::class);
        Mail::fake();
    }

    public function test_platform_admin_registers_company_with_branches_modules_and_hr_admin(): void
    {
        $response = $this->actingAs($this->createPlatformAdmin())->postJson('/api/v1/platform/companies', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Kairuki Hospital')
            ->assertJsonPath('data.branches.0.is_headquarters', true)
            ->assertJsonPath('data.branches.1.is_headquarters', false)
            ->assertJsonCount(2, 'data.modules')
            ->assertJsonPath('data.admin.email', 'hr@kairuki.test')
            ->assertJsonPath('data.admin.username', 'hr@kairuki.test')
            ->assertJsonPath('data.admin.has_set_password', false)
            ->assertJsonPath('data.employees_count', 1)
            ->assertJsonPath('meta.invite_sent', true);

        $company = Company::firstWhere('name', 'Kairuki Hospital');
        $this->assertSame(['+255765919184', 'info@kairukihospital.org', 'Mikocheni, Regent Street, P.O. Box 4567', 'Dar es Salaam', 'Tanzania'],
            [$company->phone, $company->email, $company->address, $company->city, $company->country]);
        $user = User::firstWhere('email', 'hr@kairuki.test');
        $this->assertSame($company->id, $user->company_id);
        $this->assertSame('Hamisi Umbaliche', $user->display_name);
        $this->assertSame('Mikocheni', $user->branch->name);
        $this->assertSame('Human Resources', $user->employee->department()->withoutGlobalScopes()->first()->name);

        Mail::assertSent(CompanyInviteMail::class, fn ($mail) => $mail->hasTo('hr@kairuki.test'));
    }

    public function test_invited_admin_sets_password_and_logs_in(): void
    {
        $this->actingAs($this->createPlatformAdmin())->postJson('/api/v1/platform/companies', $this->payload())->assertCreated();

        $url = null;
        Mail::assertSent(CompanyInviteMail::class, function ($mail) use (&$url) {
            $url = $mail->url;
            return true;
        });
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith(config('app.frontend_url') . '/set-password?', $url);

        $this->startNewRequestAsGuest();
        $this->postJson('/api/v1/auth/set-password', [
            'token' => $query['token'], 'email' => $query['email'],
            'password' => 'Secret123', 'password_confirmation' => 'Secret123',
        ])->assertNoContent();

        $this->withHeader('Referer', 'http://localhost:4200')
            ->postJson('/api/v1/auth/login', ['username' => 'hr@kairuki.test', 'password' => 'Secret123'])
            ->assertOk()
            ->assertJsonPath('data.company.name', 'Kairuki Hospital')
            ->assertJsonPath('data.roles', ['company-admin'])
            ->assertJsonFragment(['slug' => 'leave']);
    }

    public function test_invite_link_cannot_be_used_with_a_wrong_token(): void
    {
        $this->createCompany('Alpha');

        $this->postJson('/api/v1/auth/set-password', [
            'token' => 'wrong', 'email' => 'hr@alpha.test', 'password' => 'Secret123', 'password_confirmation' => 'Secret123',
        ])->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_validation(): void
    {
        [, $existing] = $this->createCompany('Alpha');

        $this->actingAs($this->createPlatformAdmin())->postJson('/api/v1/platform/companies', $this->payload([
            'company' => ['name' => 'Alpha'],
            'branches' => [['name' => 'A', 'is_headquarters' => true], ['name' => 'B', 'is_headquarters' => true]],
            'admin' => ['email' => $existing->email],
            'modules' => [['slug' => 'no-such-module']],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['company.name', 'branches', 'admin.email', 'modules.0.slug']);

        $this->postJson('/api/v1/platform/companies', $this->payload(['company' => ['name' => 'Beta', 'phone' => '', 'email' => '', 'address' => '', 'city' => '', 'country' => '']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['company.phone', 'company.email', 'company.address', 'company.city', 'company.country']);
    }

    public function test_company_users_and_guests_cannot_use_platform_endpoints(): void
    {
        [, $companyAdmin] = $this->createCompany('Alpha');

        $this->getJson('/api/v1/platform/companies')->assertUnauthorized();
        $this->actingAs($companyAdmin)->getJson('/api/v1/platform/companies')->assertForbidden();
        $this->actingAs($companyAdmin)->postJson('/api/v1/platform/companies', $this->payload())->assertForbidden();
    }

    public function test_list_search_and_detail(): void
    {
        $this->createCompany('Alpha');
        [$beta] = $this->createCompany('Beta');
        $owner = $this->createPlatformAdmin();

        $this->actingAs($owner)->getJson('/api/v1/platform/companies?filter[search]=bet')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Beta')->assertJsonPath('meta.total', 1);

        $this->actingAs($owner)->getJson("/api/v1/platform/companies/{$beta->id}")
            ->assertOk()->assertJsonPath('data.admin.username', 'hr_beta')->assertJsonPath('data.users_count', 1);
    }

    public function test_suspended_company_users_are_blocked(): void
    {
        [$company, $admin] = $this->createCompany('Alpha');

        $this->actingAs($this->createPlatformAdmin())
            ->patchJson("/api/v1/platform/companies/{$company->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->actingAs($admin)->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('code', 'company_suspended');

        $this->startNewRequestAsGuest();
        $this->withHeader('Referer', 'http://localhost:4200')
            ->postJson('/api/v1/auth/login', ['username' => 'hr_alpha', 'password' => 'password'])
            ->assertUnprocessable();
    }

    public function test_platform_admin_edits_company_details(): void
    {
        [$company, $admin] = $this->createCompany('Alpha');
        $url = "/api/v1/platform/companies/{$company->id}";
        $details = ['name' => 'Alpha Hospital', 'phone' => '+255765919184', 'email' => 'info@alpha.org', 'address' => 'P.O. Box 4567',
            'city' => 'Dar es Salaam', 'country' => 'Tanzania', 'tin' => '100-200-300', 'website' => 'https://alpha.org'];

        $this->actingAs($admin)->patchJson($url, $details)->assertForbidden();

        $this->actingAs($this->createPlatformAdmin())->patchJson($url, $details)
            ->assertOk()->assertJsonPath('data.name', 'Alpha Hospital')->assertJsonPath('data.phone', '+255765919184')->assertJsonPath('data.city', 'Dar es Salaam');
        $this->patchJson($url, ['phone' => '', 'email' => '', 'address' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['phone', 'email', 'address']);
        $this->assertSame('info@alpha.org', $company->fresh()->email);
    }

    public function test_modules_can_be_replaced(): void
    {
        [$company, $admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'leave'], ['slug' => 'payroll']]]);

        $this->actingAs($this->createPlatformAdmin())
            ->putJson("/api/v1/platform/companies/{$company->id}/modules", ['modules' => [['slug' => 'fleet']]])
            ->assertOk()->assertJsonCount(1, 'data.modules')->assertJsonPath('data.modules.0.slug', 'fleet');

        $slugs = collect($this->actingAs($admin)->getJson('/api/v1/me')->json('data.modules'))->pluck('slug');
        $this->assertTrue($slugs->contains('fleet'));
        $this->assertFalse($slugs->contains('leave'));
    }
}
