<?php

namespace Modules\Planning\Actions;

use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Planning\Models\AnnualPlan;
use Modules\Planning\Models\DepartmentPlan;
use Modules\Planning\Models\StrategicObjective;
use Modules\Planning\Workflow\DepartmentPlanProcess;
use Modules\Tasks\Models\TaskActivity;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManagePlanning
{
    public function __construct(private readonly WorkflowEngine $workflow) {}

    public function open(AnnualPlan $plan): int
    {
        if ($plan->status === 'closed') {
            throw ValidationException::withMessages(['plan' => 'This annual plan is closed.']);
        }

        return DB::transaction(function () use ($plan) {
            $plan->update(['status' => 'open']);
            $created = 0;
            $existing = $plan->departmentPlans()->pluck('department_id')->all();
            foreach (Department::whereNull('parent_id')->where('is_active', true)->whereNotIn('id', $existing)->get() as $d) {
                DepartmentPlan::create(['annual_plan_id' => $plan->id, 'department_id' => $d->id, 'status' => 'draft']);
                $created++;
            }

            return $created;
        });
    }

    public function close(AnnualPlan $plan): AnnualPlan
    {
        if ($plan->status !== 'open') {
            throw ValidationException::withMessages(['plan' => 'Only an open annual plan can be closed.']);
        }
        $plan->update(['status' => 'closed']);

        return $plan;
    }

    public function saveActivity(DepartmentPlan $dp, array $data, User $by, ?TaskActivity $activity = null): TaskActivity
    {
        $this->ensureEditable($dp);
        if (! empty($data['strategic_objective_id'])) {
            $objective = StrategicObjective::find($data['strategic_objective_id']);
            if (! $objective || $objective->strategic_plan_id !== $dp->annualPlan->strategic_plan_id) {
                throw ValidationException::withMessages(['strategic_objective_id' => 'Choose an objective of the strategic plan this annual plan follows.']);
            }
        }
        $fields = array_intersect_key($data, array_flip(['code', 'title', 'description', 'output', 'target', 'kpi', 'quarters', 'strategic_objective_id']));
        if ($activity) {
            $activity->update($fields);

            return $activity;
        }

        return TaskActivity::create($fields + [
            'annual_plan_id' => $dp->annual_plan_id, 'department_id' => $dp->department_id, 'year' => $dp->annualPlan->year,
            'is_active' => false, 'created_by' => $by->id,
        ]);
    }

    public function deleteActivity(DepartmentPlan $dp, TaskActivity $activity): void
    {
        $this->ensureEditable($dp);
        if ($activity->tasks()->exists()) {
            throw ValidationException::withMessages(['activity' => 'This activity has tasks; it cannot be removed.']);
        }
        $activity->delete();
    }

    public function submit(DepartmentPlan $dp, User $by): DepartmentPlan
    {
        $this->ensureEditable($dp);
        if (! $dp->activities()->exists()) {
            throw ValidationException::withMessages(['plan' => 'Add the department\'s activities before submitting.']);
        }

        return DB::transaction(function () use ($dp, $by) {
            $dp->update(['status' => 'pending', 'submitted_by' => $by->id, 'submitted_at' => now(), 'decision_comment' => null]);
            if (! $this->workflow->start(DepartmentPlanProcess::KEY, $dp, $by)) {
                $this->approved($dp);
            }

            return $dp->refresh();
        });
    }

    public function withdraw(DepartmentPlan $dp, User $by): DepartmentPlan
    {
        if ($dp->status !== 'pending') {
            throw ValidationException::withMessages(['plan' => 'Only a plan waiting for approval can be withdrawn.']);
        }
        if ($instance = $dp->workflow()->where('status', WorkflowInstance::PENDING)->first()) {
            $this->workflow->cancel($instance, $instance->initiator ?? $by);
        }
        $dp->refresh()->status === 'pending' && $dp->update(['status' => 'draft']);

        return $dp->refresh();
    }

    public function reopen(DepartmentPlan $dp): DepartmentPlan
    {
        if ($dp->status !== 'approved' || $dp->annualPlan->status !== 'open') {
            throw ValidationException::withMessages(['plan' => 'Only an approved plan of an open annual plan can be reopened.']);
        }
        $dp->update(['status' => 'draft', 'decided_at' => null]);

        return $dp;
    }

    public function approved(DepartmentPlan $dp): void
    {
        $dp->update(['status' => 'approved', 'decided_at' => now()]);
        $dp->activities()->update(['is_active' => true]);
        $dp->submitter?->notify(new Notice('Department plan approved', "The {$dp->annualPlan->title} plan of {$dp->department?->name} is approved. Its activities can now take tasks.", "/planning/annual/{$dp->annual_plan_id}/departments/{$dp->department_id}", 'bullseye'));
    }

    public function rejected(DepartmentPlan $dp, ?string $comment, bool $withdrawn): void
    {
        $dp->update(['status' => $withdrawn ? 'draft' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
        if (! $withdrawn) {
            $dp->submitter?->notify(new Notice('Department plan returned', "The {$dp->annualPlan->title} plan of {$dp->department?->name} was returned: {$comment}", "/planning/annual/{$dp->annual_plan_id}/departments/{$dp->department_id}", 'bullseye'));
        }
    }

    private function ensureEditable(DepartmentPlan $dp): void
    {
        if ($dp->annualPlan->status !== 'open') {
            throw ValidationException::withMessages(['plan' => 'The annual plan is not open for planning.']);
        }
        if (! in_array($dp->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages(['plan' => 'This department plan is '.strtolower(DepartmentPlan::STATUS_LABELS[$dp->status]).'; it cannot be changed now.']);
        }
    }
}
