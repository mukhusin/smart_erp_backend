<?php

namespace Modules\Workflow\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Workflow\Models\WorkflowActionLog;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowTemplate;
use Modules\Workflow\Services\WorkflowActions;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class WorkflowActionsTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private User $staff;

    private User $approver;

    private User $deputy;

    private User $watcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-18 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin'], ['slug' => 'fleet']]]);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->run($this->company, function () {
            Role::findOrCreate('transport-approver', 'web');
            Role::findOrCreate('transport-watch', 'web');
            $this->staff = tap(User::factory()->create(['company_id' => $this->company->id, 'email' => 'staff@example.test']))->assignRole('employee');
            $this->approver = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('transport-approver');
            $this->deputy = User::factory()->create(['company_id' => $this->company->id]);
            $this->watcher = tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('transport-watch');
            $dept = Department::create(['name' => 'Outreach', 'is_active' => true]);
            Employee::create(['employee_no' => 'S1', 'fname' => 'Staff', 'lname' => 'X', 'gender' => 'Male', 'department_id' => $dept->id, 'user_id' => $this->staff->id,
                'employment_status' => 'confirmed', 'date_of_birth' => '1985-01-01']);
            WorkflowTemplate::where('process', 'transport-request')->delete();
            WorkflowTemplate::create(['process' => 'transport-request', 'company_branch_id' => null, 'is_active' => true])
                ->steps()->create(['step_order' => 1, 'name' => 'Transport', 'approver_type' => 'role', 'role_id' => Role::where('name', 'transport-approver')->value('id'), 'allow_send_back' => true]);
        });
    }

    private function request(): int
    {
        return $this->actingAs($this->staff)->postJson('/api/v1/fleet/requests', ['purpose' => 'Outreach', 'destination' => 'Kibaha', 'departs_at' => '2026-11-25 08:00',
            'returns_at' => '2026-11-25 17:00', 'passengers' => 2])->assertCreated()->json('data.id');
    }

    public function test_actions_notify_call_another_system_and_escalate(): void
    {
        Http::fake(['hooks.example/*' => Http::response(['ok' => true])]);
        $this->actingAs($this->admin)->getJson('/api/v1/admin/workflows/transport-request/actions')->assertOk()
            ->assertJsonPath('data.effects.approved', 'The transport office is told to allocate a vehicle.');
        $watch = Role::where('name', 'transport-watch')->where('company_id', $this->company->id)->value('id');
        $this->postJson('/api/v1/admin/workflows/transport-request/actions', ['event' => 'approved', 'action' => 'notify', 'target' => 'requester', 'channels' => ['app', 'email'],
            'message' => 'Good news: {title} is approved.'])->assertCreated();
        $this->postJson('/api/v1/admin/workflows/transport-request/actions', ['event' => 'started', 'action' => 'notify', 'target' => 'role', 'role_id' => $watch, 'channels' => ['app']])->assertCreated();
        $this->postJson('/api/v1/admin/workflows/transport-request/actions', ['event' => 'started', 'action' => 'webhook', 'url' => 'https://hooks.example/erp', 'secret' => 'abc'])->assertCreated();
        $this->postJson('/api/v1/admin/workflows/transport-request/actions', ['event' => 'approved', 'action' => 'escalate', 'target' => 'user', 'user_id' => $this->deputy->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['action']);
        $this->postJson('/api/v1/admin/workflows/transport-request/actions', ['event' => 'overdue', 'after_days' => 2, 'action' => 'escalate', 'target' => 'user', 'user_id' => $this->deputy->id])->assertCreated();

        $first = $this->request();
        $this->assertSame(1, $this->watcher->notifications()->count());
        Http::assertSent(fn ($r) => $r->url() === 'https://hooks.example/erp' && $r['event'] === 'started' && $r->hasHeader('X-Signature', hash_hmac('sha256', $r->body(), 'abc')));
        $wf = WorkflowInstance::where('process', 'transport-request')->where('status', 'pending')->value('id');
        $this->actingAs($this->approver)->postJson("/api/v1/workflow/requests/{$wf}/approve")->assertOk();
        $this->assertSame(1, $this->staff->notifications()->where('data->message', 'like', 'Good news:%')->count());

        $this->request();
        $wf2 = WorkflowInstance::where('process', 'transport-request')->where('status', 'pending')->value('id');
        $this->actingAs($this->deputy)->postJson("/api/v1/workflow/requests/{$wf2}/approve")->assertUnprocessable();
        $this->travelTo('2026-11-20 10:00:00');
        $this->assertSame(1, app(CompanyContext::class)->run($this->company, fn () => app(WorkflowActions::class)->overdue()));
        $this->assertSame(0, app(CompanyContext::class)->run($this->company, fn () => app(WorkflowActions::class)->overdue()));
        $this->assertSame(1, $this->deputy->notifications()->where('data->title', 'Approval escalated to you')->count());
        $this->actingAs($this->deputy)->postJson("/api/v1/workflow/requests/{$wf2}/approve")->assertOk();

        $logs = app(CompanyContext::class)->run($this->company, fn () => WorkflowActionLog::count());
        $this->assertGreaterThanOrEqual(5, $logs);
        $this->actingAs($this->admin)->getJson('/api/v1/admin/workflows/transport-request/actions')->assertJsonCount(4, 'data.actions')->assertJsonPath('data.actions.2.secret_set', true);
        $export = $this->getJson('/api/v1/admin/workflows/export')->json();
        $tr = collect($export['workflows'])->firstWhere('process', 'transport-request');
        $this->assertCount(4, $tr['actions']);
        $this->assertArrayNotHasKey('secret', $tr['actions'][2]);
        $this->assertNotNull($first);
    }
}
