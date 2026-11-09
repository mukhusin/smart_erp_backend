<?php

namespace Modules\Workflow\Services;

use App\Models\HeadOfDepartment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\AccessService;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Modules\Workflow\Models\WorkflowStep;
use Modules\Workflow\Models\WorkflowTemplate;

/**
 * Runs approvals for every module. A module calls start(); if the company configured no workflow for the process,
 * start() returns null and the module applies the change at once. Otherwise approvers act until the process's
 * onApproved / onRejected is called.
 */
class WorkflowEngine
{
    public function __construct(
        private readonly WorkflowCatalog $catalog,
        private readonly AccessService $access,
    ) {}

    /** The active workflow for a process: the branch's own, else the company's. Null = no approval needed. */
    public function templateFor(string $process, ?int $branchId): ?WorkflowTemplate
    {
        $candidates = WorkflowTemplate::where('process', $process)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('company_branch_id')->when($branchId, fn ($q) => $q->orWhere('company_branch_id', $branchId)))
            ->whereHas('steps')
            ->with('steps')
            ->get();

        return $candidates->firstWhere('company_branch_id', $branchId) ?? $candidates->firstWhere('company_branch_id', null);
    }

    public function start(string $processKey, Model $subject, User $initiator): ?WorkflowInstance
    {
        $process = $this->catalog->get($processKey);
        $branchId = $process->branchId($subject);
        $template = $this->templateFor($processKey, $branchId);
        if (! $template) {
            return null;
        }

        return DB::transaction(function () use ($process, $processKey, $subject, $initiator, $template, $branchId) {
            $instance = WorkflowInstance::create([
                'process' => $processKey,
                'workflow_template_id' => $template->id,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'company_branch_id' => $branchId,
                'department_id' => $process->departmentId($subject),
                'status' => WorkflowInstance::PENDING,
                'current_step_order' => $template->steps->first()->step_order,
                'initiated_by' => $initiator->id,
            ]);
            foreach ($template->steps as $i => $step) {
                $instance->steps()->create($this->copy($step) + ['status' => $i === 0 ? WorkflowInstanceStep::PENDING : WorkflowInstanceStep::WAITING]);
            }

            return $instance->load('steps');
        });
    }

    public function canAct(User $user, WorkflowInstance $instance): bool
    {
        if ($instance->status !== WorkflowInstance::PENDING || $instance->initiated_by === $user->id) {
            return false;
        }
        $step = $instance->relationLoaded('steps') ? $instance->currentStep() : $instance->steps()->where('status', WorkflowInstanceStep::PENDING)->first();

        return $step !== null && $this->isApprover($user, $instance, $step);
    }

    public function approve(WorkflowInstance $instance, User $user, ?string $comment): WorkflowInstance
    {
        return $this->act($instance, $user, function (WorkflowInstance $instance, WorkflowInstanceStep $step) use ($user, $comment) {
            $step->update(['status' => WorkflowInstanceStep::APPROVED, 'acted_by' => $user->id, 'acted_at' => now(), 'comment' => $comment]);
            $next = $instance->steps()->where('status', WorkflowInstanceStep::WAITING)->orderBy('step_order')->orderBy('id')->first();

            if ($next) {
                $next->update(['status' => WorkflowInstanceStep::PENDING]);
                $instance->update(['current_step_order' => $next->step_order]);
            } else {
                $instance->update(['status' => WorkflowInstance::APPROVED, 'current_step_order' => null, 'completed_at' => now()]);
                $this->catalog->get($instance->process)->onApproved($instance->subject);
            }
        });
    }

    public function reject(WorkflowInstance $instance, User $user, string $comment): WorkflowInstance
    {
        return $this->act($instance, $user, function (WorkflowInstance $instance, WorkflowInstanceStep $step) use ($user, $comment) {
            $step->update(['status' => WorkflowInstanceStep::REJECTED, 'acted_by' => $user->id, 'acted_at' => now(), 'comment' => $comment]);
            $instance->update(['status' => WorkflowInstance::REJECTED, 'current_step_order' => null, 'completed_at' => now()]);
            $this->catalog->get($instance->process)->onRejected($instance->subject, $comment);
        });
    }

    /** Back to an earlier step for another look; that step and the ones after it run again. */
    public function sendBack(WorkflowInstance $instance, User $user, int $toStepOrder, string $comment): WorkflowInstance
    {
        return $this->act($instance, $user, function (WorkflowInstance $instance, WorkflowInstanceStep $step) use ($user, $toStepOrder, $comment) {
            if (! $step->allow_send_back || $toStepOrder >= $step->step_order) {
                throw ValidationException::withMessages(['to_step' => 'Choose an earlier step to send this back to.']);
            }
            $step->update(['status' => WorkflowInstanceStep::SENT_BACK, 'acted_by' => $user->id, 'acted_at' => now(), 'comment' => $comment]);
            $instance->steps()->where('status', WorkflowInstanceStep::WAITING)->delete();

            $template = $instance->steps()->where('step_order', '>=', $toStepOrder)->orderBy('id')->get()->unique('step_order')->sortBy('step_order');
            foreach ($template->values() as $i => $old) {
                $instance->steps()->create(collect($old->only($old->getFillable()))->except(['status', 'acted_by', 'acted_at', 'comment'])->all()
                    + ['status' => $i === 0 ? WorkflowInstanceStep::PENDING : WorkflowInstanceStep::WAITING]);
            }
            $instance->update(['current_step_order' => $toStepOrder]);
        });
    }

    /** The requester withdraws a pending request. */
    public function cancel(WorkflowInstance $instance, User $user): WorkflowInstance
    {
        return DB::transaction(function () use ($instance, $user) {
            $instance = WorkflowInstance::lockForUpdate()->findOrFail($instance->id);
            if ($instance->status !== WorkflowInstance::PENDING || $instance->initiated_by !== $user->id) {
                throw ValidationException::withMessages(['workflow' => 'Only the requester can withdraw a pending request.']);
            }
            $instance->steps()->whereIn('status', [WorkflowInstanceStep::PENDING, WorkflowInstanceStep::WAITING])->delete();
            $instance->update(['status' => WorkflowInstance::CANCELLED, 'current_step_order' => null, 'completed_at' => now()]);
            $this->catalog->get($instance->process)->onRejected($instance->subject, 'Withdrawn by the requester.');

            return $instance->load('steps');
        });
    }

    /** Pending requests the user can act on now. */
    public function inbox(User $user): Collection
    {
        return WorkflowInstance::where('status', WorkflowInstance::PENDING)
            ->where('initiated_by', '!=', $user->id)
            ->with(['steps', 'subject', 'initiator'])
            ->latest('id')
            ->get()
            ->filter(fn (WorkflowInstance $i) => $this->canAct($user, $i))
            ->values();
    }

    private function act(WorkflowInstance $instance, User $user, \Closure $do): WorkflowInstance
    {
        return DB::transaction(function () use ($instance, $user, $do) {
            // Lock so two approvers cannot act on the same step at once.
            $instance = WorkflowInstance::lockForUpdate()->with('steps')->findOrFail($instance->id);
            if (! $this->canAct($user, $instance)) {
                throw ValidationException::withMessages(['workflow' => 'This request is not waiting for you (it may have been handled already).']);
            }
            $do($instance, $instance->currentStep());

            return $instance->refresh()->load('steps');
        });
    }

    private function isApprover(User $user, WorkflowInstance $instance, WorkflowInstanceStep $step): bool
    {
        return match ($step->approver_type) {
            WorkflowStep::APPROVER_USER => $step->user_id === $user->id,
            WorkflowStep::APPROVER_ROLE => $step->role !== null
                && in_array($step->role->name, $this->access->roles($user), true)   // includes handed-over roles
                && (! $step->same_branch || $user->company_branch_id === $instance->company_branch_id),
            WorkflowStep::APPROVER_DEPARTMENT_HEAD => $instance->department_id !== null
                && HeadOfDepartment::where('department_id', $instance->department_id)->where('is_active', true)
                    ->whereHas('employee', fn ($q) => $q->withoutGlobalScopes()->where('user_id', $user->id))->exists(),
            default => false,
        };
    }

    private function copy(WorkflowStep $step): array
    {
        return $step->only(['step_order', 'name', 'approver_type', 'role_id', 'user_id', 'same_branch', 'allow_send_back', 'approve_label', 'reject_label']);
    }
}
