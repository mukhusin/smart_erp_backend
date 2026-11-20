<?php

namespace Modules\Workflow\Services;

use App\Models\Employee;
use App\Models\HeadOfDepartment;
use App\Models\User;
use App\Services\ReportingLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Modules\Workflow\Models\WorkflowStep;

/** The people a pending step is waiting for (for trackers; the engine still decides who may act). */
class StepApprovers
{
    /** @var array<string, Collection<int, User>> */
    private array $memo = [];

    /** @return Collection<int, User> */
    public function for(WorkflowInstance $instance, WorkflowInstanceStep $step): Collection
    {
        if ($step->escalated_user_id || $step->escalated_role_id) {
            return $step->escalated_user_id ? User::whereKey($step->escalated_user_id)->get() : $this->withRole($instance->company_id, (int) $step->escalated_role_id, false, null);
        }

        return match ($step->approver_type) {
            WorkflowStep::APPROVER_USER => User::whereKey($step->user_id)->get(),
            WorkflowStep::APPROVER_ROLE => $this->withRole($instance->company_id, (int) $step->role_id, (bool) $step->same_branch, $instance->company_branch_id),
            WorkflowStep::APPROVER_DEPARTMENT_HEAD => $this->heads($instance->department_id, null),
            WorkflowStep::APPROVER_SECTION_HEAD => $this->sectionHeads($instance),
            WorkflowStep::APPROVER_SUPERVISOR => $this->supervisor($instance),
            default => collect(),
        };
    }

    /** @return Collection<int, User> */
    private function withRole(int $companyId, int $roleId, bool $sameBranch, ?int $branchId): Collection
    {
        $key = "role:$companyId:$roleId:".($sameBranch ? (int) $branchId : '*');

        return $this->memo[$key] ??= User::where('company_id', $companyId)->where('is_active', true)
            ->whereIn('id', DB::table(config('permission.table_names.model_has_roles'))->where('role_id', $roleId)->where('model_type', (new User)->getMorphClass())->pluck('model_id'))
            ->when($sameBranch, fn ($q) => $q->where('company_branch_id', $branchId))->with('employee')->get();
    }

    /** @return Collection<int, User> */
    private function heads(?int $departmentId, ?string $level): Collection
    {
        if (! $departmentId) {
            return collect();
        }

        return HeadOfDepartment::with(['employee' => fn ($q) => $q->withoutGlobalScopes()->with('user')])->where('department_id', $departmentId)->where('is_active', true)
            ->when($level, fn ($q) => $q->where('level', $level))->get()->map(fn ($h) => $h->employee?->user)->filter()->unique('id')->values();
    }

    /** @return Collection<int, User> */
    private function sectionHeads(WorkflowInstance $instance): Collection
    {
        $employee = $instance->employee_id ? Employee::withoutGlobalScopes()->find($instance->employee_id) : null;
        $heads = $this->heads($employee?->section_id, HeadOfDepartment::SECTION);

        return $heads->isNotEmpty() ? $heads : $this->heads($employee?->department_id ?? $instance->department_id, HeadOfDepartment::DEPARTMENT);
    }

    /** @return Collection<int, User> */
    private function supervisor(WorkflowInstance $instance): Collection
    {
        $boss = $instance->employee_id ? app(ReportingLine::class)->supervisorIdOf($instance->employee_id) : null;

        return $boss ? User::whereIn('id', Employee::withoutGlobalScopes()->whereKey($boss)->pluck('user_id')->filter())->get() : collect();
    }
}
