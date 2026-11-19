<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class LanguageTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_user_language_drives_messages(): void
    {
        [$company, $admin] = $this->createCompany('Alpha');
        $this->actingAs($admin)->getJson('/api/v1/me')->assertJsonPath('data.user.locale', 'en');
        $this->putJson('/api/v1/me/language', ['locale' => 'fr'])->assertUnprocessable()->assertJsonPath('errors.locale.0', 'The selected locale is invalid.');
        $this->putJson('/api/v1/me/language', ['locale' => 'sw'])->assertOk()->assertJsonPath('message', 'Lugha imehifadhiwa.');
        $this->getJson('/api/v1/me')->assertJsonPath('data.user.locale', 'sw');
        $this->putJson('/api/v1/me/language', [])->assertUnprocessable()->assertJsonPath('errors.locale.0', 'locale inahitajika.');

        $company->update(['default_locale' => 'sw']);
        $admin->forceFill(['locale' => null])->save();
        $this->actingAs($admin->fresh())->getJson('/api/v1/me')->assertJsonPath('data.user.locale', 'sw');
    }

    public function test_guest_language_from_header(): void
    {
        $this->postJson('/api/v1/auth/login', [], ['X-Locale' => 'sw'])->assertUnprocessable()->assertJsonPath('errors.username.0', fn ($m) => str_contains($m, 'inahitajika'));
    }
}
