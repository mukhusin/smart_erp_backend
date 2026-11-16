<?php

namespace Modules\Appraisals\Support;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Appraisals\Models\Appraisal;
use Modules\Core\Services\AccessService;
use Modules\Hr\Support\EmployeeScope;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowStep;
use Modules\Workflow\Services\WorkflowEngine;

class AppraisalAccess
{
    public function __construct(private readonly AccessService $access, private readonly EmployeeScope $scope, private readonly WorkflowEngine $workflow) {}

    public function manages(User $user): bool
    {
        return $this->access->can($user, 'appraisals.manage');
    }

    public function seesAll(User $user): bool
    {
        return $this->manages($user) || $this->access->can($user, 'appraisals.view');
    }

    public function reviews(User $user): bool
    {
        return $this->access->can($user, 'appraisals.review');
    }

    public function isEmployee(User $user, Appraisal $a): bool
    {
        return $a->employee?->user_id === $user->id;
    }

    public function visible(Builder $query, User $user, string $view): Builder
    {
        $me = $user->employee?->id ?? 0;

        return match ($view) {
            'all' => $this->seesAll($user) ? $query : $query->whereRaw('1 = 0'),
            'team' => $this->reviews($user) || $this->seesAll($user)
                ? $query->where(fn ($q) => $q->whereIn('employee_id', $this->scope->apply(Employee::query(), $user)->select('id'))
                    ->orWhereIn('id', $this->awaiting($user)))->where('employee_id', '!=', $me)
                : $query->whereRaw('1 = 0'),
            default => $query->where('employee_id', $me),
        };
    }

    public function awaiting(User $user): array
    {
        return WorkflowInstance::where('process', 'appraisal')->where('status', WorkflowInstance::PENDING)->where('initiated_by', '!=', $user->id)->with('steps.role')->get()
            ->filter(fn (WorkflowInstance $i) => $this->workflow->canAct($user, $i))->pluck('subject_id')->all();
    }

    public function overStaff(User $user, Appraisal $a): bool
    {
        return ! $this->isEmployee($user, $a) && $a->employee && (($this->reviews($user) && $this->scope->allows($user, $a->employee)) || $this->seesAll($user));
    }

    public function canView(User $user, Appraisal $a): bool
    {
        return $this->isEmployee($user, $a) || $this->overStaff($user, $a) || $this->raterFor($user, $a) !== null;
    }

    public function raterFor(User $user, Appraisal $a): ?string
    {
        if ($a->status !== 'review') {
            return null;
        }
        $instance = $a->workflow()->where('status', 'pending')->with('steps.role')->first();
        if (! $instance || ! $this->workflow->canAct($user, $instance)) {
            return null;
        }

        return match ($instance->currentStep()?->approver_type) {
            WorkflowStep::APPROVER_SUPERVISOR => 'supervisor',
            WorkflowStep::APPROVER_DEPARTMENT_HEAD, WorkflowStep::APPROVER_SECTION_HEAD => 'hod',
            default => 'hr',
        };
    }

    public function canMidYear(User $user, Appraisal $a): bool
    {
        return $a->status === 'open' && $a->cycle?->status === 'open' && ($a->cycle->config['mid_year'] ?? false) && $a->cycle->mid_year_date
            && ($this->manages($user) || (! $this->isEmployee($user, $a) && $this->reviews($user) && $a->employee && $this->scope->allows($user, $a->employee)));
    }
}
