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

    public function test_notices_show_in_the_readers_language(): void
    {
        [, $admin] = $this->createCompany('Alpha');
        $admin->notify(new \Modules\Core\Notifications\Notice('Payslip ready', 'Your payslip for :month is ready. Net pay: :net.', '/self-service/payslips', 'receipt', params: ['month' => 'November 2026', 'net' => '800,000.00']));
        $this->travel(1)->minutes();
        $admin->notify(new \Modules\Core\Notifications\Notice('Leave approved', 'Free text that is not translated.'));
        $this->assertSame('Your payslip for November 2026 is ready. Net pay: 800,000.00.', $admin->notifications()->where('data->title', 'Payslip ready')->first()->data['message']);
        $this->actingAs($admin)->getJson('/api/v1/me/notifications')->assertJsonPath('data.0.title', 'Leave approved')->assertJsonPath('data.1.message', 'Your payslip for November 2026 is ready. Net pay: 800,000.00.');
        $this->putJson('/api/v1/me/language', ['locale' => 'sw'])->assertOk();
        $this->actingAs($admin->fresh())->getJson('/api/v1/me/notifications')->assertJsonPath('data.0.title', 'Likizo imeidhinishwa')->assertJsonPath('data.0.message', 'Free text that is not translated.')
            ->assertJsonPath('data.1.title', 'Hati ya mshahara iko tayari')->assertJsonPath('data.1.message', 'Hati yako ya mshahara ya November 2026 iko tayari. Mshahara halisi: 800,000.00.');
    }
}
