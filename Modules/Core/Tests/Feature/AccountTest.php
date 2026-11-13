<?php

namespace Modules\Core\Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Mail\EmailChangeCodeMail;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_user_changes_own_password(): void
    {
        [, $admin] = $this->createCompany('Alpha');
        $admin->forceFill(['password' => Hash::make('Secret123')])->save();

        $this->actingAs($admin)->putJson('/api/v1/me/password', ['current_password' => 'wrong', 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456'])
            ->assertUnprocessable()->assertJsonValidationErrors(['current_password' => 'not correct']);
        $this->putJson('/api/v1/me/password', ['current_password' => 'Secret123', 'password' => 'short', 'password_confirmation' => 'short'])->assertJsonValidationErrors(['password']);
        $this->putJson('/api/v1/me/password', ['current_password' => 'Secret123', 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456'])->assertNoContent();
        $this->assertTrue(Hash::check('NewPass456', $admin->refresh()->password));
        $this->getJson('/api/v1/me/notifications')->assertJsonPath('data.0.title', 'Password changed');
    }

    public function test_user_changes_email_with_a_code_sent_to_the_new_email(): void
    {
        Mail::fake();
        [$company, $admin] = $this->createCompany('Alpha');
        $admin->forceFill(['password' => Hash::make('Secret123')])->save();
        $other = User::factory()->create(['company_id' => $company->id, 'email' => 'taken@example.com']);
        $employee = app(CompanyContext::class)->run($company, fn () => Employee::where('user_id', $admin->id)->first());

        $this->actingAs($admin)->postJson('/api/v1/me/email', ['email' => $other->email, 'current_password' => 'Secret123'])->assertJsonValidationErrors(['email' => 'Another account']);
        $this->postJson('/api/v1/me/email', ['email' => 'New@Example.com', 'current_password' => 'Secret123'])->assertStatus(202)->assertJsonPath('data.sent_to', 'new@example.com');
        $code = null;
        Mail::assertSent(EmailChangeCodeMail::class, function (EmailChangeCodeMail $m) use (&$code) {
            $code = $m->code;

            return $m->hasTo('new@example.com');
        });
        $wrong = $code === '111111' ? '222222' : '111111';
        $this->postJson('/api/v1/me/email/confirm', ['code' => $wrong])->assertJsonValidationErrors(['code' => 'not correct']);
        $this->postJson('/api/v1/me/email/confirm', ['code' => $code])->assertOk()->assertJsonPath('data.email', 'new@example.com');
        $this->assertSame('new@example.com', $admin->refresh()->email);
        if ($employee) {
            $this->assertSame('new@example.com', app(CompanyContext::class)->run($company, fn () => $employee->refresh()->email));
        }
        $this->postJson('/api/v1/me/email/confirm', ['code' => $code])->assertJsonValidationErrors(['code' => 'expired']);
    }
}
