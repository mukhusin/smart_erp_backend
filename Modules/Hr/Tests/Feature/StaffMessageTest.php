<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\CompanySmsSetting;
use App\Models\Department;
use App\Models\Employee;
use App\Models\MessageRecipient;
use App\Models\Messaging;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Support\DefaultRoles;
use Modules\Hr\Mail\StaffMessageMail;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class StaffMessageTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private int $ward;

    private User $ashaUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-13 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr']]]);
        setPermissionsTeamId($this->company->id);

        app(CompanyContext::class)->run($this->company, function () {
            $this->ward = Department::create(['name' => 'Ward', 'is_active' => true])->id;
            $this->ashaUser = User::factory()->create(['company_id' => $this->company->id, 'email' => 'asha@alpha.test', 'is_active' => true]);
            $make = fn (array $a) => Employee::create($a + ['lname' => 'X', 'gender' => 'Female', 'emp_type' => 'full_time', 'employment_status' => 'confirmed', 'department_id' => $this->ward]);
            $make(['employee_no' => 'W1', 'fname' => 'Asha', 'phone_number' => '0712 345 678', 'user_id' => $this->ashaUser->id]);
            $make(['employee_no' => 'W2', 'fname' => 'Juma', 'phone_number' => '0755123456', 'email' => 'juma@alpha.test']);
            $make(['employee_no' => 'W3', 'fname' => 'Neema']);
        });
        $this->giveActiveContracts($this->company);
    }

    private function sms(array $o = []): array
    {
        return $o + ['type' => 'SMS', 'message' => 'Hello {first_name}, staff meeting at 2pm.', 'recipient_type' => 'department', 'department_id' => $this->ward];
    }

    public function test_previews_and_sends_sms_with_names(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/hr/messages/preview', $this->sms())->assertOk()
            ->assertJsonPath('data.total', 3)->assertJsonPath('data.reachable', 2)->assertJsonPath('data.missing.0', 'Neema X (W3)');

        $this->actingAs($this->admin)->postJson('/api/v1/hr/messages', $this->sms())->assertUnprocessable()->assertJsonValidationErrors('type');

        app(CompanyContext::class)->run($this->company, fn () => CompanySmsSetting::create(['is_enabled' => true, 'driver' => 'log', 'sender_id' => 'ALPHA']));
        $id = $this->actingAs($this->admin)->postJson('/api/v1/hr/messages', $this->sms())->assertCreated()
            ->assertJsonPath('data.recipients', 2)->assertJsonPath('data.skipped', 1)->json('data.id');

        $this->actingAs($this->admin)->getJson("/api/v1/hr/messages/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'Sent')->assertJsonPath('data.sent', 2);
        $this->assertSame('Hello Asha, staff meeting at 2pm.', MessageRecipient::where('recipient_address', '255712345678')->value('individual_message'));
        $this->actingAs($this->admin)->getJson('/api/v1/hr/messages')->assertOk()->assertJsonPath('data.0.audience', 'Department: Ward');
    }

    public function test_email_and_notice(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->postJson('/api/v1/hr/messages', $this->sms(['type' => 'Email', 'subject' => 'Meeting', 'message' => 'Dear {name}, see you.']))
            ->assertCreated()->assertJsonPath('data.recipients', 2);
        Mail::assertSent(StaffMessageMail::class, fn ($m) => $m->hasTo('juma@alpha.test') && $m->body === 'Dear Juma X, see you.');
        Mail::assertSent(StaffMessageMail::class, 2);

        $this->actingAs($this->admin)->postJson('/api/v1/hr/messages', $this->sms(['type' => 'Notice', 'subject' => 'Meeting', 'message' => 'Today 2pm']))
            ->assertCreated()->assertJsonPath('data.recipients', 1);
        $this->assertSame('Meeting', $this->ashaUser->notifications()->first()->data['title']);
    }

    public function test_schedules_cancels_and_sends_due(): void
    {
        Mail::fake();
        $email = $this->sms(['type' => 'Email', 'subject' => 'Later', 'recipient_type' => 'individual', 'employee_ids' => [Employee::withoutCompanyScope()->where('employee_no', 'W2')->value('id')]]);

        $first = $this->actingAs($this->admin)->postJson('/api/v1/hr/messages', $email + ['scheduled_at' => '2026-11-13 10:00'])->assertCreated()
            ->assertJsonPath('data.status', 'Pending')->json('data.id');
        $second = $this->actingAs($this->admin)->postJson('/api/v1/hr/messages', $email + ['scheduled_at' => '2026-11-13 11:00'])->json('data.id');
        $this->actingAs($this->admin)->postJson("/api/v1/hr/messages/{$second}/cancel")->assertOk()->assertJsonPath('data.status', 'Cancelled');
        Mail::assertNothingSent();

        $this->travelTo('2026-11-13 12:00:00');
        $this->artisan('hr:send-messages')->assertSuccessful();
        Mail::assertSent(StaffMessageMail::class, 1);
        $this->assertSame('Sent', Messaging::withoutCompanyScope()->find($first)->status);
        $this->assertSame('Cancelled', Messaging::withoutCompanyScope()->find($second)->status);
        $this->actingAs($this->admin)->postJson("/api/v1/hr/messages/{$first}/cancel")->assertUnprocessable();
    }

    public function test_scope_permission_and_role_top_up(): void
    {
        $nobody = User::factory()->create(['company_id' => $this->company->id]);
        $this->actingAs($nobody)->postJson('/api/v1/hr/messages/preview', $this->sms())->assertForbidden();
        $clerk = User::factory()->create(['company_id' => $this->company->id]);
        $clerk->givePermissionTo(['hr.messages.send', 'hr.employees.view-section']);
        $this->actingAs($clerk)->postJson('/api/v1/hr/messages/preview', $this->sms(['recipient_type' => 'all']))->assertOk()->assertJsonPath('data.total', 0);

        $role = Role::where('name', 'hr-officer')->where('company_id', $this->company->id)->first();
        $role->revokePermissionTo('hr.messages.send');
        DefaultRoles::addMissingToCompanies();
        $this->assertTrue($role->fresh()->hasPermissionTo('hr.messages.send'));
    }
}
