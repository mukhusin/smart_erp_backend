<?php

namespace Modules\Reporting\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Payments\Models\PaymentRequest;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class ExecutiveDashboardTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_directors_see_their_areas_and_the_director_general_sees_all(): void
    {
        $this->travelTo('2026-11-18 09:00:00');
        $modules = array_map(fn ($s) => ['slug' => $s], ['hr', 'admin', 'accounting', 'budgeting', 'planning', 'payments', 'procurement', 'inventory', 'assets', 'fleet', 'reports', 'self-service', 'tasks']);
        [$company] = $this->createCompany('Alpha', ['modules' => $modules]);
        setPermissionsTeamId($company->id);
        [$finance, $hr, $dg, $staff] = app(CompanyContext::class)->run($company, function () use ($company) {
            $ward = Department::create(['name' => 'Ward']);
            foreach (['2026-03-01', '2026-10-05'] as $i => $date) {
                Employee::create(['employee_no' => "E$i", 'fname' => "E$i", 'lname' => 'X', 'gender' => 'Female', 'department_id' => $ward->id, 'emp_type' => 'full_time',
                    'employment_status' => 'confirmed', 'employment_date' => $date]);
            }
            $users = array_map(fn ($role) => tap(User::factory()->create(['company_id' => $company->id]))->assignRole($role), ['director-finance', 'director-hr-admin', 'director-general', 'employee']);
            PaymentRequest::create(['number' => 'PR-1', 'kind' => 'payment', 'title' => 'Supplies', 'total' => 5000, 'status' => 'pending', 'department_id' => $ward->id, 'requested_by' => $users[3]->id]);

            return $users;
        });

        $this->actingAs($finance)->getJson('/api/v1/reports/executive')->assertOk()->assertJsonPath('data.hr', null)
            ->assertJsonPath('data.payments.requests_pending', 1)->assertJsonPath('data.procurement.open_orders', 0)->assertJsonPath('data.inventory.stock_value', 0)->assertJsonCount(12, 'data.months');
        $this->actingAs($hr)->getJson('/api/v1/reports/executive')->assertOk()->assertJsonPath('data.payments', null)
            ->assertJsonPath('data.hr.joined.10', 1)->assertJsonFragment(['label' => 'Ward', 'value' => 2]);
        $this->actingAs($dg)->getJson('/api/v1/reports/executive')->assertOk()->assertJsonFragment(['label' => 'Ward', 'value' => 2])->assertJsonPath('data.payments.requests_pending', 1);
        $this->actingAs($staff)->getJson('/api/v1/reports/executive')->assertForbidden();
    }

    public function test_payment_tracker_shows_stage_waiting_person_and_approver_speed(): void
    {
        $this->travelTo('2026-11-16 09:00:00');
        $modules = array_map(fn ($s) => ['slug' => $s], ['hr', 'admin', 'accounting', 'budgeting', 'payments', 'reports', 'self-service', 'tasks']);
        [$company] = $this->createCompany('Alpha', ['modules' => $modules]);
        setPermissionsTeamId($company->id);
        [$dg, $approver, $request] = app(CompanyContext::class)->run($company, function () use ($company) {
            $ward = Department::create(['name' => 'Ward']);
            $dg = tap(User::factory()->create(['company_id' => $company->id]))->assignRole('director-general');
            $approver = tap(User::factory()->create(['company_id' => $company->id, 'username' => 'fin.manager']))->assignRole('finance-manager');
            $staff = tap(User::factory()->create(['company_id' => $company->id]))->assignRole('employee');
            \Modules\Workflow\Models\WorkflowTemplate::where('process', 'payment-request')->delete();
            \Modules\Workflow\Models\WorkflowTemplate::create(['process' => 'payment-request', 'is_active' => true])->steps()->create(['step_order' => 1, 'name' => 'Finance', 'approver_type' => 'user', 'user_id' => $approver->id]);
            $pr = PaymentRequest::create(['number' => 'PR-1', 'kind' => 'payment', 'title' => 'Supplies', 'total' => 5000, 'status' => 'pending', 'department_id' => $ward->id, 'requested_by' => $staff->id, 'submitted_at' => now()]);
            app(\Modules\Workflow\Services\WorkflowEngine::class)->start('payment-request', $pr, $staff);

            return [$dg, $approver, $pr];
        });

        $this->travel(2)->days();
        $this->actingAs($dg)->getJson('/api/v1/reports/executive/payments')->assertOk()
            ->assertJsonPath('data.0.stage', 'Approval: Finance')->assertJsonPath('data.0.waiting_days', 2)->assertJsonCount(1, 'data.0.waiting_on')
            ->assertJsonPath('meta.ageing.0.value', 1);
        $this->getJson('/api/v1/reports/executive/approvers')->assertOk()->assertJsonPath('data.0.user_id', $approver->id)->assertJsonPath('data.0.waiting', 1)->assertJsonPath('data.0.oldest_days', 2);

        $wf = $request->workflow()->first();
        $this->actingAs($approver)->postJson("/api/v1/workflow/requests/{$wf->id}/approve")->assertOk();
        $this->actingAs($dg)->getJson('/api/v1/reports/executive/approvers')->assertJsonPath('data.0.acted', 1)->assertJsonPath('data.0.avg_hours', 48)->assertJsonPath('data.0.waiting', 0);
        $this->getJson('/api/v1/reports/executive/payments')->assertJsonPath('data.0.stage', 'Approved, waiting for a payment voucher');
    }
}
