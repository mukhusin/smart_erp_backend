<?php

namespace Modules\Platform\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class CompanyBrandingTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_platform_admin_sets_logo_background_and_colors(): void
    {
        Storage::fake('public');
        [$company, $admin] = $this->createCompany('Alpha');
        $owner = $this->createPlatformAdmin();

        $this->actingAs($owner)->post("/api/v1/platform/companies/{$company->id}/branding", [
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            'login_background' => UploadedFile::fake()->image('bg.jpg', 1920, 1080),
            'primary_color' => '#0A7C6E',
            'secondary_color' => '#123456',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.primary_color', '#0a7c6e')
            ->assertJsonPath('data.secondary_color', '#123456');

        $company->refresh();
        Storage::disk('public')->assertExists($company->logo);
        Storage::disk('public')->assertExists($company->login_background);

        // Shown on the login page and to the company's users
        $this->getJson('/api/v1/auth/login-branding?org=alpha')
            ->assertJsonPath('data.organization.primary_color', '#0a7c6e')
            ->assertJsonPath('data.organization.logo_url', $company->logo_url);
        $this->actingAs($admin)->getJson('/api/v1/me')->assertJsonPath('data.company.primary_color', '#0a7c6e');

        // Back to defaults
        $old = $company->logo;
        $this->actingAs($owner)->post("/api/v1/platform/companies/{$company->id}/branding",
            ['remove_logo' => 'true', 'primary_color' => ''], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.logo_url', null)->assertJsonPath('data.primary_color', null);
        Storage::disk('public')->assertMissing($old);
    }

    public function test_invalid_files_and_colors_are_rejected(): void
    {
        [$company] = $this->createCompany('Alpha');

        $this->actingAs($this->createPlatformAdmin())->post("/api/v1/platform/companies/{$company->id}/branding", [
            'logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
            'primary_color' => 'red',
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['logo', 'primary_color']);
    }

    public function test_platform_admin_chooses_where_brand_colors_are_used(): void
    {
        [$company, $admin] = $this->createCompany('Alpha');
        $url = "/api/v1/platform/companies/{$company->id}/branding";
        $json = ['Accept' => 'application/json'];

        $this->actingAs($admin)->getJson('/api/v1/me')->assertJsonPath('data.company.theme', ['sidebar' => 'plain', 'navbar' => 'plain', 'tables' => 'plain', 'cards' => 'plain']);

        $this->actingAs($this->createPlatformAdmin())->post($url, ['theme_sidebar' => 'solid', 'theme_tables' => 'tinted', 'theme_cards' => 'accent'], $json)
            ->assertOk()->assertJsonPath('data.theme', ['sidebar' => 'solid', 'navbar' => 'plain', 'tables' => 'tinted', 'cards' => 'accent']);
        $this->post($url, ['theme_navbar' => 'solid'], $json)->assertOk()->assertJsonPath('data.theme.sidebar', 'solid')->assertJsonPath('data.theme.navbar', 'solid');
        $this->post($url, ['theme_cards' => 'solid', 'theme_sidebar' => 'neon'], $json)->assertUnprocessable()->assertJsonValidationErrors(['theme_cards', 'theme_sidebar']);

        $this->actingAs($admin->fresh())->getJson('/api/v1/me')->assertJsonPath('data.company.theme.sidebar', 'solid')->assertJsonPath('data.company.theme.tables', 'tinted');
    }

    public function test_company_users_cannot_change_branding(): void
    {
        [$company, $admin] = $this->createCompany('Alpha');

        $this->actingAs($admin)->post("/api/v1/platform/companies/{$company->id}/branding", ['primary_color' => '#000000'], ['Accept' => 'application/json'])
            ->assertForbidden();
    }
}
