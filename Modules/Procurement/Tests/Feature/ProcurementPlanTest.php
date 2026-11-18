<?php

namespace Modules\Procurement\Tests\Feature;

use App\Models\Department;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Workflow\Models\WorkflowTemplate;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class ProcurementPlanTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_plan_from_budget_approved_and_checked_on_requisitions(): void
    {
        $this->travelTo('2026-05-10 09:00:00');
        [$company, $admin] = $this->createCompany('Alpha', ['modules' => array_map(fn ($s) => ['slug' => $s], ['hr', 'admin', 'accounting', 'budgeting', 'inventory', 'procurement'])]);
        setPermissionsTeamId($company->id);
        [$budget, $dept, $laptops, $fuel] = app(CompanyContext::class)->run($company, function () use ($admin) {
            WorkflowTemplate::whereIn('process', ['procurement-plan', 'purchase-requisition'])->delete();
            $dept = Department::create(['name' => 'ICT', 'is_active' => true]);
            $year = app(FiscalCalendar::class)->createYear('2026-01-01');
            $asset = Account::create(['code' => '1600', 'name' => 'Computers', 'type' => 'asset', 'is_postable' => true, 'is_budgetable' => true, 'is_active' => true]);
            $exp = Account::create(['code' => '5100', 'name' => 'Fuel', 'type' => 'expense', 'is_postable' => true, 'is_budgetable' => true, 'is_active' => true]);
            $rev = Account::create(['code' => '4000', 'name' => 'Grants', 'type' => 'revenue', 'is_postable' => true, 'is_budgetable' => true, 'is_active' => true]);
            $budget = Budget::create(['fiscal_year_id' => $year->id, 'title' => 'Budget 2026', 'phasing' => 'quarter', 'status' => 'approved', 'created_by' => $admin->id]);
            $laptops = BudgetItem::create(['budget_id' => $budget->id, 'department_id' => $dept->id, 'account_id' => $asset->id, 'description' => 'Laptops', 'quantity' => 4, 'unit_cost' => 2500000,
                'original_amount' => 10000000, 'revised_amount' => 10000000]);
            $laptops->periods()->create(['period' => 2, 'amount' => 10000000]);
            $fuel = BudgetItem::create(['budget_id' => $budget->id, 'department_id' => $dept->id, 'account_id' => $exp->id, 'description' => 'Fuel', 'quantity' => 1, 'unit_cost' => 3000000,
                'frequency' => 1, 'original_amount' => 3000000, 'revised_amount' => 3000000]);
            $fuel->periods()->create(['period' => 1, 'amount' => 3000000]);
            BudgetItem::create(['budget_id' => $budget->id, 'department_id' => $dept->id, 'account_id' => $rev->id, 'description' => 'Grant', 'quantity' => 1, 'unit_cost' => 1, 'original_amount' => 1, 'revised_amount' => 1]);

            return [$budget, $dept, $laptops, $fuel];
        });

        $this->actingAs($admin);
        $id = $this->postJson('/api/v1/procurement/plans', ['budget_id' => $budget->id, 'from_budget' => true])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/procurement/plans', ['budget_id' => $budget->id])->assertUnprocessable()->assertJsonValidationErrors(['budget_id']);
        $plan = $this->getJson("/api/v1/procurement/plans/{$id}")->assertOk()->assertJsonCount(2, 'data.lines')->assertJsonPath('data.total', 13000000)->json('data');
        $lap = collect($plan['lines'])->firstWhere('budget_item_id', $laptops->id);
        $this->assertSame(2, $lap['quarter']);
        $this->assertSame('goods', $lap['category']);
        $this->assertNotNull($lap['procurement_method_id']);
        $fuelLine = collect($plan['lines'])->firstWhere('budget_item_id', $fuel->id);
        $this->assertSame('late', $fuelLine['progress']);

        $lines = collect($plan['lines'])->map(fn ($l) => collect($l)->only(['id', 'department_id', 'budget_item_id', 'description', 'category', 'procurement_method_id', 'quantity', 'estimated_unit_cost', 'quarter'])->all())->all();
        $lines[0]['quantity'] = 5;
        $this->putJson("/api/v1/procurement/plans/{$id}", ['title' => 'Plan 2026', 'lines' => $lines])->assertOk();
        $this->postJson("/api/v1/procurement/plans/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->putJson("/api/v1/procurement/settings", ['direct_limit' => 1000000, 'min_quotes' => 3, 'match_tolerance_percent' => 0, 'plan_check' => 'block'])->assertOk()->assertJsonPath('data.plan_check', 'block');

        $line = fn (array $extra = []) => $extra + ['description' => 'Laptop', 'quantity' => 2, 'estimated_unit_cost' => 2500000, 'budget_item_id' => $laptops->id];
        $req = fn (array $l, string $kind = 'normal') => $this->postJson('/api/v1/procurement/requisitions', ['department_id' => $dept->id, 'title' => 'Laptops', 'kind' => $kind,
            'special_reason' => $kind === 'special' ? 'Urgent' : null, 'lines' => [$l]])->assertCreated()->json('data.id');

        $outside = $req($line());
        $this->postJson("/api/v1/procurement/requisitions/{$outside}/submit")->assertUnprocessable()->assertJsonValidationErrors(['requisition']);
        $planLines = $this->getJson("/api/v1/procurement/plan-lines?department_id={$dept->id}&budget_id={$budget->id}")->assertOk()->json('data');
        $lapId = collect($planLines)->firstWhere('budget_item_id', $laptops->id)['id'];
        $inside = $req($line(['procurement_plan_line_id' => $lapId]));
        $this->postJson("/api/v1/procurement/requisitions/{$inside}/submit")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->getJson("/api/v1/procurement/requisitions/{$inside}")->assertJsonPath('data.unplanned', false)->assertJsonPath('data.lines.0.procurement_plan_line_id', $lapId);
        $this->getJson("/api/v1/procurement/plan-lines?department_id={$dept->id}")->assertJsonPath('data.'.array_search($lapId, array_column($planLines, 'id')).'.left', 5000000);

        $tooMuch = $req($line(['procurement_plan_line_id' => $lapId, 'quantity' => 3]));
        $this->postJson("/api/v1/procurement/requisitions/{$tooMuch}/submit")->assertUnprocessable();
        $urgent = $req($line(), 'special');
        $this->postJson("/api/v1/procurement/requisitions/{$urgent}/submit")->assertOk();
        $this->getJson("/api/v1/procurement/requisitions/{$urgent}")->assertJsonPath('data.unplanned', true);

        $this->getJson("/api/v1/procurement/plans/{$id}")->assertJsonPath('data.requisitioned', 5000000);
        $this->postJson("/api/v1/procurement/plans/{$id}/revise")->assertOk()->assertJsonPath('data.status', 'draft');
        $keep = array_values(array_filter($lines, fn ($l) => $l['id'] !== $lapId));
        $this->putJson("/api/v1/procurement/plans/{$id}", ['title' => 'Plan 2026', 'lines' => $keep])->assertUnprocessable()->assertJsonValidationErrors(['lines']);
        $this->getJson('/api/v1/procurement/plans')->assertOk()->assertJsonPath('data.0.revision', 1)->assertJsonPath('data.0.requisitioned', 5000000);
    }
}
