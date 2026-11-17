<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\PayrollSheet;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Hr\Jobs\GeneratePayrollJob;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class PayrollBackgroundTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_payroll_is_worked_out_in_the_background_and_the_user_is_told(): void
    {
        [$company, $admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'payroll']]]);
        Queue::fake();
        $sheet = $this->actingAs($admin)->postJson('/api/v1/payroll/sheets', ['year' => 2026, 'month' => 11])->assertCreated()->assertJsonPath('data.processing', true)->json('data');
        Queue::assertPushed(GeneratePayrollJob::class, fn ($job) => $job->sheetId === $sheet['id'] && $job->userId === $admin->id);
        $this->postJson("/api/v1/payroll/sheets/{$sheet['id']}/generate")->assertUnprocessable();
        $this->postJson("/api/v1/payroll/sheets/{$sheet['id']}/submit")->assertUnprocessable();

        app()->call([new GeneratePayrollJob($sheet['id'], $admin->id), 'handle']);
        $this->getJson("/api/v1/payroll/sheets/{$sheet['id']}")->assertOk()->assertJsonPath('data.sheet.processing', false);
        $this->assertSame('Payroll ready', $admin->fresh()->notifications()->latest()->first()->data['title'] ?? null);

        app(CompanyContext::class)->run($company, fn () => PayrollSheet::find($sheet['id'])->forceFill(['processing_started_at' => now()->subHours(2)])->save());
        $this->postJson("/api/v1/payroll/sheets/{$sheet['id']}/generate")->assertOk()->assertJsonPath('data.processing', true);
        (new GeneratePayrollJob($sheet['id'], $admin->id))->failed(new \RuntimeException('boom'));
        $this->getJson("/api/v1/payroll/sheets/{$sheet['id']}")->assertJsonPath('data.sheet.processing', false)->assertJsonPath('data.sheet.processing_error', 'Something went wrong while working out the payroll. Try again or contact support.');
    }
}
