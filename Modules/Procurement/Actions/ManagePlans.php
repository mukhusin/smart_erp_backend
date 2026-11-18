<?php

namespace Modules\Procurement\Actions;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Budgeting\Models\Budget;
use Modules\Budgeting\Models\BudgetItem;
use Modules\Core\Notifications\Notice;
use Modules\Procurement\Models\ProcurementMethod;
use Modules\Procurement\Models\ProcurementPlan;
use Modules\Procurement\Models\ProcurementPlanLine;
use Modules\Procurement\Models\ProcurementSetting;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\PurchaseRequisitionLine;
use Modules\Procurement\Workflow\ProcurementPlanProcess;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManagePlans
{
    public const LIVE = ['pending', 'approved', 'ordered', 'closed'];

    public function __construct(private readonly WorkflowEngine $workflow) {}

    public function create(array $data, User $by): ProcurementPlan
    {
        $budget = Budget::with('fiscalYear')->findOrFail($data['budget_id']);
        if (ProcurementPlan::where('budget_id', $budget->id)->exists()) {
            throw ValidationException::withMessages(['budget_id' => 'This budget already has a procurement plan.']);
        }

        return DB::transaction(function () use ($budget, $data, $by) {
            $plan = ProcurementPlan::create(['budget_id' => $budget->id, 'title' => $data['title'] ?? 'Procurement plan '.$budget->fiscalYear?->name, 'status' => 'draft', 'created_by' => $by->id]);
            if (! empty($data['from_budget'])) {
                $this->loadBudget($plan, $budget);
            }

            return $plan->refresh();
        });
    }

    private function loadBudget(ProcurementPlan $plan, Budget $budget): void
    {
        ProcurementMethod::ensureDefaults();
        $methods = ProcurementMethod::where('is_active', true)->get();
        $items = BudgetItem::with(['periods', 'account'])->where('budget_id', $budget->id)->whereHas('account', fn ($q) => $q->whereIn('type', ['expense', 'asset']))->orderBy('department_id')->orderBy('id')->get();
        foreach ($items as $i) {
            $amount = round((float) ($i->revised_amount ?: $i->original_amount), 2);
            if ($amount <= 0) {
                continue;
            }
            $qty = max(1, round($i->quantity * max(1, $i->frequency), 4));
            $first = $i->periods->where('amount', '>', 0)->sortBy('period')->first()?->period ?? 1;
            $category = $i->account?->type === 'asset' ? 'goods' : 'services';
            $plan->lines()->create(['department_id' => $i->department_id, 'budget_item_id' => $i->id, 'description' => $i->description ?: $i->label(), 'category' => $category,
                'procurement_method_id' => $this->suggest($methods, $amount, $category)?->id, 'quantity' => $qty, 'estimated_unit_cost' => round($amount / $qty, 2), 'amount' => $amount,
                'quarter' => $this->quarter($budget, (int) $first)]);
        }
    }

    private function quarter(Budget $budget, int $period): int
    {
        return match ($budget->phasing) {
            'month' => (int) min(4, max(1, ceil($period / 3))),
            'year' => 1,
            default => min(4, max(1, $period)),
        };
    }

    public function suggest(Collection $methods, float $amount, string $category): ?ProcurementMethod
    {
        $fits = $methods->filter(fn ($m) => $m->allows($amount) && ! $m->needs_reason && ! $m->highest_wins && $m->sourcing !== 'framework');

        return $fits->first(fn ($m) => $m->category === $category) ?? $fits->first(fn ($m) => $m->category === null);
    }

    public function save(ProcurementPlan $plan, array $data): ProcurementPlan
    {
        if (! $plan->editable()) {
            throw ValidationException::withMessages(['plan' => 'Only a draft or returned plan can be changed.']);
        }
        $budgetItems = BudgetItem::where('budget_id', $plan->budget_id)->whereIn('id', array_filter(array_column($data['lines'], 'budget_item_id')))->get()->keyBy('id');
        foreach ($data['lines'] as $n => $l) {
            $bi = ! empty($l['budget_item_id']) ? $budgetItems->get($l['budget_item_id']) : null;
            if (! empty($l['budget_item_id']) && (! $bi || $bi->department_id !== (int) $l['department_id'])) {
                throw ValidationException::withMessages(["lines.{$n}.budget_item_id" => 'Pick a budget line of this department in this budget.']);
            }
        }

        return DB::transaction(function () use ($plan, $data) {
            $plan->update(['title' => $data['title']]);
            $keep = [];
            foreach ($data['lines'] as $l) {
                $row = ['department_id' => $l['department_id'], 'budget_item_id' => $l['budget_item_id'] ?? null, 'item_id' => $l['item_id'] ?? null, 'description' => $l['description'],
                    'category' => $l['category'], 'procurement_method_id' => $l['procurement_method_id'] ?? null, 'unit_id' => $l['unit_id'] ?? null,
                    'quantity' => round((float) $l['quantity'], 4), 'estimated_unit_cost' => round((float) $l['estimated_unit_cost'], 2),
                    'amount' => round((float) $l['quantity'] * (float) $l['estimated_unit_cost'], 2), 'quarter' => $l['quarter'], 'notes' => $l['notes'] ?? null];
                $line = ! empty($l['id']) ? $plan->lines()->whereKey($l['id'])->first() : null;
                $line ? $line->update($row) : $line = $plan->lines()->create($row);
                $keep[] = $line->id;
            }
            $gone = $plan->lines()->whereNotIn('id', $keep);
            if ((clone $gone)->whereHas('requisitionLines')->exists()) {
                throw ValidationException::withMessages(['lines' => 'A line already used by a requisition cannot be removed; set its quantity instead.']);
            }
            $gone->delete();

            return $plan->refresh();
        });
    }

    public function submit(ProcurementPlan $plan, User $by): ProcurementPlan
    {
        if (! $plan->editable()) {
            throw ValidationException::withMessages(['plan' => 'This plan is already submitted.']);
        }
        if (! $plan->lines()->exists()) {
            throw ValidationException::withMessages(['plan' => 'Add the planned purchases first.']);
        }

        return DB::transaction(function () use ($plan, $by) {
            $plan->update(['status' => 'pending', 'decision_comment' => null]);
            if (! $this->workflow->start(ProcurementPlanProcess::KEY, $plan, $by)) {
                $this->approved($plan);
            }

            return $plan->refresh();
        });
    }

    public function withdraw(ProcurementPlan $plan, User $by): ProcurementPlan
    {
        if ($plan->status !== 'pending') {
            throw ValidationException::withMessages(['plan' => 'Only a plan waiting for approval can be withdrawn.']);
        }
        $instance = $plan->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->rejected($plan, null, true);

        return $plan->refresh();
    }

    public function revise(ProcurementPlan $plan): ProcurementPlan
    {
        if ($plan->status !== 'approved') {
            throw ValidationException::withMessages(['plan' => 'Only an approved plan can be revised.']);
        }
        $plan->update(['status' => 'draft', 'revision' => $plan->revision + 1, 'decision_comment' => null]);

        return $plan->refresh();
    }

    public function approved(ProcurementPlan $plan): void
    {
        $plan->update(['status' => 'approved', 'approved_at' => now()]);
        $plan->creator?->notify(new Notice('Procurement plan approved', "{$plan->title} is approved.", "/procurement/plans/{$plan->id}", 'calendar-check'));
    }

    public function rejected(ProcurementPlan $plan, ?string $comment, bool $withdrawn): void
    {
        $plan->update(['status' => $withdrawn ? 'draft' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment]);
        if (! $withdrawn) {
            $plan->creator?->notify(new Notice('Procurement plan returned', "{$plan->title} was returned: {$comment}", "/procurement/plans/{$plan->id}", 'calendar-x'));
        }
    }

    /** @return array<int, array{requisitioned: float, ordered: float}> */
    public function used(iterable $lineIds, ?int $exceptRequisition = null): array
    {
        $rows = PurchaseRequisitionLine::query()->whereIn('procurement_plan_line_id', collect($lineIds)->all())
            ->whereHas('requisition', fn ($q) => $q->whereIn('status', self::LIVE)->when($exceptRequisition, fn ($q, $id) => $q->whereKeyNot($id)))
            ->get(['procurement_plan_line_id', 'amount', 'ordered_quantity', 'estimated_unit_cost']);
        $out = [];
        foreach ($rows as $r) {
            $o = &$out[$r->procurement_plan_line_id];
            $o['requisitioned'] = round(($o['requisitioned'] ?? 0) + $r->amount, 2);
            $o['ordered'] = round(($o['ordered'] ?? 0) + $r->ordered_quantity * $r->estimated_unit_cost, 2);
            unset($o);
        }

        return $out;
    }

    public function progress(ProcurementPlan $plan): array
    {
        $plan->loadMissing('budget.fiscalYear', 'lines');
        $used = $this->used($plan->lines->pluck('id'));
        $start = $plan->budget?->fiscalYear?->start_date ? Carbon::parse($plan->budget->fiscalYear->start_date) : null;
        $out = [];
        foreach ($plan->lines as $l) {
            $u = $used[$l->id] ?? ['requisitioned' => 0, 'ordered' => 0];
            $ends = $start ? $start->copy()->addMonthsNoOverflow($l->quarter * 3)->subDay() : null;
            $status = match (true) {
                $l->amount > 0 && $u['requisitioned'] >= $l->amount - 0.01 => 'done',
                $u['requisitioned'] > 0 => 'started',
                $ends && $ends->lt(today()) => 'late',
                default => 'not_started',
            };
            $out[$l->id] = $u + ['status' => $status, 'quarter_ends' => $ends?->toDateString()];
        }

        return $out;
    }

    /** Link check while saving a requisition: each linked plan line must be in this budget's plan and department. */
    public function checkLinks(array $lines, int $budgetId, int $departmentId): void
    {
        $ids = array_filter(array_column($lines, 'procurement_plan_line_id'));
        if (! $ids) {
            return;
        }
        $plan = ProcurementPlan::approvedFor($budgetId);
        $found = $plan ? ProcurementPlanLine::where('procurement_plan_id', $plan->id)->where('department_id', $departmentId)->whereIn('id', $ids)->pluck('id')->all() : [];
        foreach ($lines as $n => $l) {
            if (! empty($l['procurement_plan_line_id']) && ! in_array((int) $l['procurement_plan_line_id'], $found, true)) {
                throw ValidationException::withMessages(["lines.{$n}.procurement_plan_line_id" => 'Pick a line of the approved procurement plan for this department.']);
            }
        }
    }

    /** True when the requisition is outside the approved plan; throws when the plan check blocks it. */
    public function unplanned(PurchaseRequisition $r): bool
    {
        $mode = ProcurementSetting::current()->plan_check;
        $plan = ProcurementPlan::approvedFor($r->budget_id);
        $exempt = $r->kind === 'special';
        if (! $plan) {
            if ($mode === 'block' && ! $exempt) {
                throw ValidationException::withMessages(['requisition' => 'There is no approved procurement plan for this year. Use a special requisition with a reason, or approve the plan first.']);
            }

            return false;
        }
        $planLines = ProcurementPlanLine::whereIn('id', $r->lines->pluck('procurement_plan_line_id')->filter())->get()->keyBy('id');
        $used = $this->used($planLines->keys(), $r->id);
        $asked = [];
        $outside = [];
        foreach ($r->lines as $l) {
            if (! $l->procurement_plan_line_id) {
                $outside[] = "{$l->description}: not in the plan";

                continue;
            }
            $asked[$l->procurement_plan_line_id] = ($asked[$l->procurement_plan_line_id] ?? 0) + $l->amount;
        }
        foreach ($asked as $id => $amount) {
            $pl = $planLines->get($id);
            $left = round($pl->amount - ($used[$id]['requisitioned'] ?? 0), 2);
            if ($amount > $left + 0.01) {
                $outside[] = "{$pl->description}: ".number_format($amount, 2).' asked, '.number_format(max(0, $left), 2).' left in the plan';
            }
        }
        if ($outside && $mode === 'block' && ! $exempt) {
            throw ValidationException::withMessages(['requisition' => 'Outside the approved procurement plan ('.implode('; ', array_slice($outside, 0, 5)).'). Use a special requisition with a reason, or revise the plan.']);
        }

        return $outside !== [];
    }
}
