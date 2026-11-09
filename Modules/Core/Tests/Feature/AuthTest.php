<?php

namespace Modules\Core\Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['name' => 'Test Co', 'is_active' => true]);
        $this->user = User::factory()->create([
            'username' => 'jdoe',
            'email' => 'jdoe@example.com',
            'company_id' => $company->id,
            'isActive' => true,
        ]);

        // Requests from the SPA origin are stateful (session + CSRF) through Sanctum.
        $this->withHeader('Referer', 'http://localhost:4200');
    }

    public function test_login_with_username_returns_current_user(): void
    {
        $this->postJson('/api/v1/auth/login', ['username' => 'jdoe', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.user.username', 'jdoe')
            ->assertJsonPath('data.company.name', 'Test Co')
            ->assertJsonStructure(['data' => ['user', 'employee', 'company', 'modules', 'roles', 'permissions']]);

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_login_with_email(): void
    {
        $this->postJson('/api/v1/auth/login', ['username' => 'JDOE@example.com', 'password' => 'password'])
            ->assertOk();
    }

    public function test_wrong_password_returns_422_on_username(): void
    {
        $this->postJson('/api/v1/auth/login', ['username' => 'jdoe', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $this->user->update(['isActive' => false]);

        $this->postJson('/api/v1/auth/login', ['username' => 'jdoe', 'password' => 'password'])
            ->assertUnprocessable();
    }

    public function test_me_requires_login(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonStructure(['message']);
    }

    public function test_login_from_another_device_ends_the_old_session(): void
    {
        app(\App\Services\SingleDeviceSessionManager::class)->registerLogin($this->user->id, 'other-device-session');

        $this->actingAs($this->user)->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'session_replaced');
    }

    public function test_me_returns_current_user(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'jdoe@example.com');
    }

    public function test_logout(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertGuest('web');
    }
}
