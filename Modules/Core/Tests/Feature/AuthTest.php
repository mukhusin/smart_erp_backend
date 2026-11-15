<?php

namespace Modules\Core\Tests\Feature;

use App\Models\User;
use App\Services\SingleDeviceSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->user] = $this->createCompany('Test Co');

        // Requests from the SPA origin are stateful (session + CSRF) through Sanctum.
        $this->withHeader('Referer', 'http://localhost:4200');
    }

    public function test_login_with_username_returns_current_user(): void
    {
        $this->postJson('/api/v1/auth/login', ['username' => 'hr_testco', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.user.username', 'hr_testco')
            ->assertJsonPath('data.user.name', 'Admin Test Co')
            ->assertJsonPath('data.user.is_platform_admin', false)
            ->assertJsonPath('data.company.name', 'Test Co')
            ->assertJsonPath('data.branch.name', 'Head Office')
            ->assertJsonPath('data.roles', ['company-admin'])
            ->assertJsonStructure(['data' => ['user', 'employee', 'company', 'branch', 'modules', 'roles', 'permissions']]);

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_login_with_email(): void
    {
        $this->postJson('/api/v1/auth/login', ['username' => 'HR@testco.test', 'password' => 'password'])->assertOk();
    }

    public function test_wrong_password_returns_422_on_username(): void
    {
        $this->postJson('/api/v1/auth/login', ['username' => 'hr_testco', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $this->user->update(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', ['username' => 'hr_testco', 'password' => 'password'])->assertUnprocessable();
    }

    public function test_me_requires_login(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonStructure(['message']);
    }

    public function test_login_from_another_device_ends_the_old_session(): void
    {
        app(SingleDeviceSessionManager::class)->registerLogin($this->user->id, 'other-device-session');

        $this->actingAs($this->user)->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'session_replaced');
    }

    public function test_idle_session_ends_and_background_polling_does_not_keep_it_alive(): void
    {
        config(['session.idle_minutes' => 20]);
        $this->postJson('/api/v1/auth/login', ['username' => 'hr_testco', 'password' => 'password'])->assertOk()
            ->assertJsonPath('data.idle_minutes', 20)->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->mock(SingleDeviceSessionManager::class, fn ($m) => $m->shouldReceive('validateSession')->andReturn(true)->shouldReceive('clearSessionRegistration'));

        $before = time() - 15 * 60;
        $this->actingAs($this->user)->withSession(['last_activity' => $before])->withHeader('X-Background', '1')->getJson('/api/v1/me/notifications')->assertOk();
        $this->assertSame($before, $this->app['session.store']->get('last_activity'), 'polling is not activity');

        $this->actingAs($this->user)->withSession(['last_activity' => $before])->withoutHeader('X-Background')->getJson('/api/v1/me')->assertOk();
        $this->assertGreaterThan($before, $this->app['session.store']->get('last_activity'));

        $this->actingAs($this->user)->withSession(['last_activity' => time() - 21 * 60])->getJson('/api/v1/me/notifications')
            ->assertUnauthorized()->assertJsonPath('code', 'session_expired');
    }

    public function test_me_lists_company_admin_permissions(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'hr@testco.test')
            ->assertJsonFragment(['admin.users.manage'])
            // No module enabled for this company except core ones, so no HR permissions yet.
            ->assertJsonMissing(['hr.employees.view']);
    }

    public function test_platform_admin_has_no_company(): void
    {
        $this->actingAs($this->createPlatformAdmin())->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.is_platform_admin', true)
            ->assertJsonPath('data.company', null)
            ->assertJsonPath('data.modules', [])
            ->assertJsonPath('data.roles', []);
    }

    public function test_logout(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertGuest('web');
    }
}
