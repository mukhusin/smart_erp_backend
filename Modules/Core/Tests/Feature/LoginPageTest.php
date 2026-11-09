<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Mail\ResetPasswordMail;
use Modules\Core\Models\LoginSlide;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class LoginPageTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_branding_for_an_organization_includes_its_slides_and_shared_ones(): void
    {
        [$company] = $this->createCompany('Kairuki Hospital', ['company' => ['short_name' => 'KH']]);
        [$other] = $this->createCompany('Other');
        LoginSlide::create(['company_id' => null, 'title' => 'For everyone']);
        LoginSlide::create(['company_id' => $company->id, 'title' => 'Kairuki news']);
        LoginSlide::create(['company_id' => $other->id, 'title' => 'Other news']);
        LoginSlide::create(['company_id' => $company->id, 'title' => 'Expired', 'ends_at' => now()->subDay()]);

        $this->assertSame('kh', $company->slug);

        $this->getJson('/api/v1/auth/login-branding?org=kh')
            ->assertOk()
            ->assertJsonPath('data.organization.name', 'Kairuki Hospital')
            ->assertJsonPath('data.slides.0.title', 'Kairuki news')
            ->assertJsonPath('data.slides.1.title', 'For everyone')
            ->assertJsonCount(2, 'data.slides');
    }

    public function test_unknown_or_suspended_organization_gets_neutral_branding(): void
    {
        [$company] = $this->createCompany('Alpha');
        $company->update(['is_active' => false]);

        $this->getJson('/api/v1/auth/login-branding?org=nope')->assertOk()->assertJsonPath('data.organization', null);
        $this->getJson('/api/v1/auth/login-branding?org=alpha')->assertOk()->assertJsonPath('data.organization', null);
    }

    public function test_slugs_are_unique(): void
    {
        [$a] = $this->createCompany('Alpha');
        [$b] = $this->createCompany('Alpha Two', ['company' => ['short_name' => 'Alpha'], 'admin' => ['email' => 'x@two.test', 'username' => 'two']]);

        $this->assertSame('alpha', $a->slug);
        $this->assertSame('alpha-2', $b->slug);
    }

    public function test_forgot_password_never_reveals_accounts_and_reset_works(): void
    {
        [, $admin] = $this->createCompany('Alpha');
        Mail::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@nowhere.test'])->assertNoContent();
        Mail::assertNothingSent();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'HR@alpha.test'])->assertNoContent();
        $url = null;
        Mail::assertSent(ResetPasswordMail::class, function ($mail) use (&$url) {
            $url = $mail->url;
            return $mail->hasTo('hr@alpha.test');
        });
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('reset', $q['purpose']);

        $this->postJson('/api/v1/auth/set-password', [
            'token' => $q['token'], 'email' => $q['email'], 'purpose' => 'reset',
            'password' => 'NewPass123', 'password_confirmation' => 'NewPass123',
        ])->assertNoContent();

        $this->withHeader('Referer', 'http://localhost:4200')
            ->postJson('/api/v1/auth/login', ['username' => 'hr_alpha', 'password' => 'NewPass123'])->assertOk();
    }
}
