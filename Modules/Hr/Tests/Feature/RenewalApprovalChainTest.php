<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\CompanySmsSetting;
use App\Models\ContractRenewal;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\HeadOfDepartment;
use App\Models\NatureOfContract;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class RenewalApprovalChainTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    /** @var array<string, User> */
    private array $users = [];

    private Employee $nurse;

    private int $stc;

    private int $permanent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-12 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'self-service']]]);
        setPermissionsTeamId($this->company->id);

        app(CompanyContext::class)->run($this->company, function () {
            $nursing = Department::create(['name' => 'Nursing', 'short_name' => 'NUR']);
            $this->stc = NatureOfContract::create(['contract_name' => 'Specific time', 'code' => 'STC', 'employment_type' => 'full_time'])->id;
            $this->permanent = NatureOfContract::create(['contract_name' => 'Permanent', 'code' => 'PRM', 'employment_type' => 'full_time'])->id;
            $person = function (string $key, string $role, ?Department $dept = null) {
                $user = User::factory()->create(['company_id' => $this->company->id]);
                $user->assignRole($role);
                $employee = Employee::create(['employee_no' => strtoupper($key), 'fname' => ucfirst($key), 'lname' => 'X', 'gender' => 'Female',
                    'department_id' => $dept?->id, 'user_id' => $user->id, 'employment_status' => 'confirmed', 'phone_number' => '0712345678']);
                $this->users[$key] = $user;

                return $employee;
            };
            $this->nurse = $person('rehema', 'employee', $nursing);
            $hod = $person('matron', 'hod', $nursing);
            HeadOfDepartment::create(['employee_id' => $hod->id, 'department_id' => $nursing->id, 'is_active' => true]);
            $person('hrm', 'hr-manager');
            $person('director', 'director');
            $person('dg', 'director-general');
            EmployeeContract::create(['employee_id' => $this->nurse->id, 'nature_of_contract_id' => $this->stc, 'contract_no' => 'REHEMA', 'basic_salary' => 900000,
                'period' => 12, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'active', 'is_active' => true]);
        });

        $role = fn (string $name) => app(CompanyContext::class)->run($this->company, fn () => Role::findByName($name, 'web')->id);
        $this->actingAs($this->admin)->putJson('/api/v1/admin/workflows/contract-renewal', ['company_branch_id' => null, 'is_active' => true, 'steps' => [
            ['name' => 'Head of Department', 'approver_type' => 'department_head'],
            ['name' => 'HR Manager', 'approver_type' => 'role', 'role_id' => $role('hr-manager')],
            ['name' => 'Director', 'approver_type' => 'role', 'role_id' => $role('director')],
            ['name' => 'Director General', 'approver_type' => 'role', 'role_id' => $role('director-general')],
        ]])->assertOk();
    }

    private function enableSms(): void
    {
        app(CompanyContext::class)->run($this->company, fn () => CompanySmsSetting::current()->fill(['is_enabled' => true, 'driver' => 'log', 'sender_id' => 'ALPHA'])->save());
    }

    private function request(array $extra = [])
    {
        return $this->actingAs($this->users['rehema'])->post('/api/v1/self/renewals', $extra + [
            'letter' => '<p>'.str_repeat('I kindly request the renewal of my contract. ', 6).'</p>', 'agreed' => 'true', 'language' => 'sw',
        ], ['Accept' => 'application/json']);
    }

    private function approve(string $who, int $workflow, int $status = 200)
    {
        return $this->actingAs($this->users[$who])->postJson("/api/v1/workflow/requests/{$workflow}/approve")->assertStatus($status);
    }

    public function test_request_goes_through_four_approvals_and_creates_the_waiting_contract(): void
    {
        $this->actingAs($this->users['rehema'])->getJson('/api/v1/self/contract')
            ->assertJsonPath('data.contract.basic_salary', 900000)->assertJsonPath('data.contract.period', 12);

        $this->enableSms();
        $id = $this->request()->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.waiting_for', 'Head of Department')->json('data.id');
        $renewal = ContractRenewal::find($id);
        $this->assertSame([$this->stc, 12, 900000.0], [$renewal->nature_of_contract_id, $renewal->period, (float) $renewal->basic_salary], 'current contract terms by default');
        $workflow = $renewal->workflow->id;

        $this->approve('hrm', $workflow, 422);
        $this->actingAs($this->users['rehema'])->putJson("/api/v1/renewals/{$id}/terms", ['nature_of_contract_id' => $this->stc, 'period' => 24, 'basic_salary' => 2000000])->assertForbidden();
        $this->actingAs($this->users['director'])->getJson("/api/v1/renewals/{$id}/review")->assertOk()->assertJsonPath('data.can_change_terms', false);

        $this->actingAs($this->users['matron'])->getJson("/api/v1/renewals/{$id}/review")->assertOk()->assertJsonPath('data.can_change_terms', true)
            ->assertJsonPath('data.terms.type', 'Specific time')->assertJsonPath('data.current_contract.basic_salary', 900000);
        $this->putJson("/api/v1/renewals/{$id}/terms", ['nature_of_contract_id' => $this->stc, 'period' => 24, 'basic_salary' => 900000, 'comment' => 'Two years'])
            ->assertOk()->assertJsonPath('data.period', 24);
        $this->approve('matron', $workflow);

        $this->actingAs($this->users['hrm'])->putJson("/api/v1/renewals/{$id}/terms", ['nature_of_contract_id' => $this->permanent, 'period' => 24, 'basic_salary' => 1100000])->assertOk();
        $this->approve('hrm', $workflow);
        $this->approve('director', $workflow);

        $this->actingAs($this->users['dg'])->getJson("/api/v1/workflow/requests/{$workflow}")->assertOk()
            ->assertJsonPath('data.subject_id', $id)
            ->assertJsonPath('data.lines', fn ($lines) => collect($lines)->contains(fn ($l) => str_contains($l, 'Terms changed at Head of Department by Matron X: Specific time, 24 months, basic salary 900,000.00 (Two years)'))
                && collect($lines)->contains(fn ($l) => str_contains($l, 'Terms changed at HR Manager by Hrm X: Permanent, 24 months, basic salary 1,100,000.00')));
        $this->assertSame('pending', ContractRenewal::find($id)->status);
        $this->approve('dg', $workflow);

        $renewal = ContractRenewal::find($id);
        $this->assertSame('completed', $renewal->status);
        $waiting = $this->nurse->waitingContract()->firstOrFail();
        $this->assertSame([$this->permanent, 24, 1100000.0, '2027-01-01', '2028-12-31'],
            [$waiting->nature_of_contract_id, $waiting->period, (float) $waiting->basic_salary, $waiting->start_date->toDateString(), $waiting->end_date->toDateString()]);

        $this->actingAs($this->users['rehema'])->getJson('/api/v1/me/notifications')->assertOk()->assertJsonPath('meta.unread', 1)
            ->assertJsonPath('data.0.title', 'Contract renewed')
            ->assertJsonPath('data.0.message', 'Your contract renewal is approved. New contract: Permanent, 24 months, from 01 Jan 2027 to 31 Dec 2028.');
        $this->postJson('/api/v1/me/notifications/read')->assertJsonPath('meta.unread', 0);
        $this->assertDatabaseHas('message_recipients', ['employee_id' => $this->nurse->id]);
        $this->actingAs($this->users['dg'])->putJson("/api/v1/renewals/{$id}/terms", ['nature_of_contract_id' => $this->stc, 'period' => 12, 'basic_salary' => 1])->assertForbidden();
    }

    public function test_employee_can_propose_terms_and_is_told_when_rejected(): void
    {
        $id = $this->request(['nature_of_contract_id' => $this->permanent, 'period' => 36, 'basic_salary' => 1500000])->assertCreated()
            ->assertJsonPath('data.basic_salary', 1500000)->json('data.id');
        $workflow = ContractRenewal::find($id)->workflow->id;
        $this->approve('matron', $workflow);
        $this->actingAs($this->users['hrm'])->postJson("/api/v1/workflow/requests/{$workflow}/reject", ['comment' => 'Budget'])->assertOk();

        $this->assertSame('rejected', ContractRenewal::find($id)->status);
        $this->assertNull($this->nurse->waitingContract()->first());
        $this->actingAs($this->users['rehema'])->getJson('/api/v1/me/notifications')
            ->assertJsonPath('data.0.message', 'Your contract renewal request was not approved. Reason: Budget');
    }

    public function test_hr_customizes_the_letter_template(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/hr/settings/renewal-letter')->assertOk()
            ->assertJsonPath('data.0.language', 'sw')->assertJsonPath('data.0.customized', false)->assertJsonPath('data.0.subject', 'YAH: OMBI LA KUONGEZEWA MKATABA WA AJIRA');
        $this->putJson('/api/v1/hr/settings/renewal-letter/sw', [
            'subject' => 'YAH: OMBI LA KUONGEZEWA MKATABA', 'salutation' => 'Kwa Mkurugenzi Mkuu,', 'closing' => 'Wako katika ujenzi wa taifa,',
            'number_label' => 'Namba', 'department_label' => 'Idara ya', 'section_label' => 'Kitengo', 'position_label' => 'Cheo', 'show_section' => false, 'show_position' => true,
        ])->assertOk()->assertJsonPath('data.customized', true);
        $this->putJson('/api/v1/hr/settings/renewal-letter/fr', ['subject' => 'x'])->assertNotFound();

        $this->actingAs($this->users['rehema'])->getJson('/api/v1/self/contract')
            ->assertJsonPath('data.letter_templates.sw.salutation', 'Kwa Mkurugenzi Mkuu,')
            ->assertJsonPath('data.letter_templates.sw.signature', ['Rehema X', 'Namba: REHEMA', 'Idara ya: Nursing'])
            ->assertJsonPath('data.letter_templates.en.subject', 'RE: REQUEST FOR RENEWAL OF EMPLOYMENT CONTRACT');
        $letter = $this->request()->assertCreated()->json('data.letter');
        $this->assertStringStartsWith('<p><strong>YAH: OMBI LA KUONGEZEWA MKATABA</strong></p><p>Kwa Mkurugenzi Mkuu,</p><p>I kindly', $letter);
        $this->assertStringEndsWith('<p>Wako katika ujenzi wa taifa,</p><p><strong>Rehema X</strong><br>Namba: REHEMA<br>Idara ya: Nursing</p>', $letter);

        $this->actingAs($this->admin)->postJson('/api/v1/hr/settings/renewal-letter/sw/reset')->assertOk()->assertJsonPath('data.customized', false);
        $this->actingAs($this->users['rehema'])->putJson('/api/v1/hr/settings/renewal-letter/sw', [])->assertForbidden();
    }
}
