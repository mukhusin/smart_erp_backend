<?php

namespace Modules\Workflow\Tests\Feature;

use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class WorkflowTransferTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_workflows_move_from_one_environment_to_another_by_names(): void
    {
        [$test, $testAdmin] = $this->createCompany('Test server', ['modules' => [['slug' => 'admin'], ['slug' => 'hr'], ['slug' => 'leave'], ['slug' => 'overtime']]]);
        [$prod, $prodAdmin] = $this->createCompany('Production', ['modules' => [['slug' => 'admin'], ['slug' => 'hr'], ['slug' => 'leave'], ['slug' => 'overtime']]]);
        setPermissionsTeamId($test->id);
        $role = app(CompanyContext::class)->run($test, fn () => Role::where('company_id', $test->id)->where('name', 'hr-manager')->value('id'));
        $boss = User::factory()->create(['company_id' => $test->id, 'email' => 'boss@example.test']);

        $this->actingAs($testAdmin)->putJson('/api/v1/admin/workflows/leave-request', ['is_active' => true, 'steps' => [
            ['name' => 'Supervisor', 'approver_type' => 'supervisor'], ['name' => 'HR manager', 'approver_type' => 'role', 'role_id' => $role, 'min_amount' => null]]])->assertOk();
        $this->putJson('/api/v1/admin/workflows/overtime-claim', ['is_active' => true, 'steps' => [['name' => 'The boss', 'approver_type' => 'user', 'user_id' => $boss->id]]])->assertOk();
        $file = $this->getJson('/api/v1/admin/workflows/export')->assertOk()->assertJsonPath('format', 'smart-erp-workflows')->json();
        $leave = collect($file['workflows'])->firstWhere('process', 'leave-request');
        $this->assertSame('hr-manager', $leave['steps'][1]['role']);
        $this->assertSame('boss@example.test', collect($file['workflows'])->firstWhere('process', 'overtime-claim')['steps'][0]['user']);

        setPermissionsTeamId($prod->id);
        $dry = $this->actingAs($prodAdmin)->postJson('/api/v1/admin/workflows/import', $file + ['dry_run' => true])->assertOk()->json('data');
        $results = collect($dry['results'])->keyBy('process');
        $this->assertSame('replaced', $results['leave-request']['action']);
        $this->assertSame('skipped', $results['overtime-claim']['action']);
        $this->assertStringContainsString('boss@example.test', $results['overtime-claim']['problems'][0]);
        $before = app(CompanyContext::class)->run($prod, fn () => WorkflowTemplate::where('process', 'leave-request')->first()->steps()->count());

        User::factory()->create(['company_id' => $prod->id, 'email' => 'boss@prod.example', 'username' => 'boss@example.test']);
        $done = $this->postJson('/api/v1/admin/workflows/import', $file)->assertOk()->json('data');
        $this->assertSame(0, $done['skipped']);
        app(CompanyContext::class)->run($prod, function () use ($prod, $before) {
            $leave = WorkflowTemplate::with('steps.role')->where('process', 'leave-request')->first();
            $this->assertSame(['Supervisor', 'HR manager'], $leave->steps->sortBy('step_order')->pluck('name')->all());
            $this->assertSame($prod->id, $leave->steps->firstWhere('approver_type', 'role')->role->company_id);
            $this->assertSame('boss@prod.example', WorkflowTemplate::with('steps.user')->where('process', 'overtime-claim')->first()->steps->first()->user->email);
            $this->assertGreaterThan(0, $before);
        });
        $this->postJson('/api/v1/admin/workflows/import', ['format' => 'other', 'version' => 1, 'workflows' => []])->assertUnprocessable();
    }
}
