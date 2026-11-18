<?php

namespace Modules\Workflow\Services;

use App\Models\HeadOfDepartment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\AccessService;
use Modules\Core\Sms\SmsService;
use Modules\Workflow\Jobs\SendWorkflowWebhook;
use Modules\Workflow\Models\WorkflowAction;
use Modules\Workflow\Models\WorkflowActionLog;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Modules\Workflow\Notifications\WorkflowAlert;
use Spatie\Permission\Models\Role;

/**
 * Runs the background actions an administrator attached to a workflow. They run after the approval is saved and never
 * stop it: a failing action is logged. The business effects of each process (budget, ledger) stay in the process.
 */
class WorkflowActions
{
    public function __construct(private readonly WorkflowCatalog $catalog, private readonly AccessService $access) {}

    public function fire(WorkflowInstance $instance, string $event, ?WorkflowInstanceStep $step = null): void
    {
        DB::afterCommit(function () use ($instance, $event, $step) {
            $actions = WorkflowAction::where('workflow_template_id', $instance->workflow_template_id)->where('event', $event)->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('step_order')->when($step, fn ($q) => $q->orWhere('step_order', $step->step_order)))->get();
            foreach ($actions as $action) {
                if ($action->min_amount !== null && ($instance->amount === null || (float) $instance->amount < $action->min_amount)) {
                    continue;
                }
                $this->run($action, $instance, $event, $step);
            }
        });
    }

    /** Steps waiting longer than an "overdue" action allows: remind or escalate, once per waiting step. */
    public function overdue(): int
    {
        $done = 0;
        $actions = WorkflowAction::where('event', 'overdue')->where('is_active', true)->whereNotNull('after_days')->get();
        foreach ($actions as $action) {
            $steps = WorkflowInstanceStep::with('instance')->where('status', WorkflowInstanceStep::PENDING)->whereNotNull('pending_since')
                ->where('pending_since', '<=', now()->subDays($action->after_days))
                ->when($action->step_order, fn ($q) => $q->where('step_order', $action->step_order))
                ->whereHas('instance', fn ($q) => $q->where('workflow_template_id', $action->workflow_template_id)->where('status', WorkflowInstance::PENDING))->get();
            foreach ($steps as $step) {
                if (WorkflowActionLog::where('workflow_action_id', $action->id)->where('workflow_instance_step_id', $step->id)->exists()) {
                    continue;
                }
                if ($action->min_amount !== null && (float) $step->instance->amount < $action->min_amount) {
                    continue;
                }
                $this->run($action, $step->instance, 'overdue', $step);
                $done++;
            }
        }

        return $done;
    }

    private function run(WorkflowAction $action, WorkflowInstance $instance, string $event, ?WorkflowInstanceStep $step): void
    {
        try {
            $detail = match ($action->action) {
                'notify' => $this->notify($action, $instance, $event, $step),
                'escalate' => $this->escalate($action, $instance, $step),
                'webhook' => $this->webhook($action, $instance, $event, $step),
                default => 'Unknown action',
            };
            if ($action->action !== 'webhook') {
                $this->log($action, $instance, $event, $step, 'done', $detail);
            }
        } catch (\Throwable $e) {
            Log::warning("Workflow action {$action->id} failed: ".$e->getMessage());
            $this->log($action, $instance, $event, $step, 'failed', $e->getMessage());
        }
    }

    private function notify(WorkflowAction $action, WorkflowInstance $instance, string $event, ?WorkflowInstanceStep $step): string
    {
        $users = $this->recipients($action, $instance, $step);
        [$title, $message, $link] = $this->text($action, $instance, $event, $step);
        $channels = $action->channels ?: ['app'];
        $notify = array_values(array_intersect(['database', 'mail'], array_map(fn ($c) => ['app' => 'database', 'email' => 'mail'][$c] ?? $c, $channels)));
        if ($notify) {
            foreach ($users as $u) {
                $u->notify(new WorkflowAlert($title, $message, $link, $notify));
            }
        }
        if (in_array('sms', $channels, true)) {
            $phones = $users->map(fn ($u) => ['employee_id' => $u->employee?->id, 'phone' => (string) ($u->employee?->phone_number ?? $u->phone ?? ''), 'message' => "{$title}: {$message}"])
                ->filter(fn ($r) => $r['phone'] !== '')->values()->all();
            if ($phones) {
                app(SmsService::class)->send($title, $phones, 'workflow', "workflow-{$action->id}-{$instance->id}");
            }
        }

        return $users->count().' person(s) by '.implode(', ', $channels);
    }

    private function escalate(WorkflowAction $action, WorkflowInstance $instance, ?WorkflowInstanceStep $step): string
    {
        if (! $step || $step->status !== WorkflowInstanceStep::PENDING) {
            return 'Step no longer waiting';
        }
        $step->update(['escalated_user_id' => $action->target === 'user' ? $action->user_id : null, 'escalated_role_id' => $action->target === 'role' ? $action->role_id : null, 'escalated_at' => now()]);
        [, $message, $link] = $this->text($action, $instance, 'overdue', $step);
        foreach ($this->recipients($action, $instance, $step) as $u) {
            $u->notify(new WorkflowAlert('Approval escalated to you', $message, $link, ['database']));
        }

        return 'Escalated to '.($action->target === 'user' ? $action->user?->display_name : $action->role?->name);
    }

    private function webhook(WorkflowAction $action, WorkflowInstance $instance, string $event, ?WorkflowInstanceStep $step): string
    {
        $summary = $this->summary($instance);
        SendWorkflowWebhook::dispatch($instance->company_id, $action->id, $instance->id, $event, [
            'event' => $event, 'process' => $instance->process, 'request_id' => $instance->id, 'subject' => ['type' => class_basename($instance->subject_type), 'id' => $instance->subject_id],
            'title' => $summary['title'] ?? null, 'amount' => $instance->amount !== null ? (float) $instance->amount : null, 'status' => $instance->status,
            'step' => $step ? ['order' => $step->step_order, 'name' => $step->name, 'status' => $step->status, 'comment' => $step->comment] : null, 'at' => now()->toIso8601String(),
        ]);

        return 'Queued';
    }

    /** @return Collection<int, User> */
    private function recipients(WorkflowAction $action, WorkflowInstance $instance, ?WorkflowInstanceStep $step): Collection
    {
        $company = User::where('company_id', $instance->company_id)->where('is_active', true);
        $users = match ($action->target) {
            'requester' => collect([$instance->initiator]),
            'user' => collect([$action->user]),
            'role' => $action->role ? $company->get()->filter(fn ($u) => in_array($action->role->name, $this->access->roles($u), true)) : collect(),
            'department_head' => $instance->department_id ? HeadOfDepartment::with(['employee' => fn ($q) => $q->withoutGlobalScopes()->with('user')])->where('department_id', $instance->department_id)
                ->where('is_active', true)->get()->map(fn ($h) => $h->employee?->user) : collect(),
            'approvers' => $company->get()->filter(fn ($u) => app(WorkflowEngine::class)->canAct($u, $instance->load('steps'))),
            default => collect(),
        };

        return $users->filter()->unique('id')->values();
    }

    /** @return array{0: string, 1: string, 2: string|null} */
    private function text(WorkflowAction $action, WorkflowInstance $instance, string $event, ?WorkflowInstanceStep $step): array
    {
        $summary = $this->summary($instance);
        $process = $this->catalog->has($instance->process) ? $this->catalog->get($instance->process)->name() : $instance->process;
        $vars = ['{title}' => (string) ($summary['title'] ?? $process), '{process}' => $process, '{requester}' => (string) $instance->initiator?->display_name,
            '{step}' => (string) $step?->name, '{event}' => WorkflowAction::EVENTS[$event] ?? $event, '{amount}' => $instance->amount !== null ? number_format((float) $instance->amount, 2) : '',
            '{days}' => $step?->pending_since ? (string) (int) $step->pending_since->diffInDays(now()) : ''];
        $default = match ($event) {
            'overdue' => '{title} has waited {days} day(s) at "{step}".',
            'step_approved' => '{title}: "{step}" approved.',
            default => '{title}: {event}.',
        };

        return ["{$process}: ".(WorkflowAction::EVENTS[$event] ?? $event), strtr($action->message ?: $default, $vars), $summary['link'] ?? null];
    }

    private function summary(WorkflowInstance $instance): array
    {
        try {
            return $instance->subject && $this->catalog->has($instance->process) ? $this->catalog->get($instance->process)->summary($instance->subject) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function log(WorkflowAction $action, WorkflowInstance $instance, string $event, ?WorkflowInstanceStep $step, string $status, string $detail): void
    {
        WorkflowActionLog::create(['company_id' => $instance->company_id, 'workflow_action_id' => $action->id, 'workflow_instance_id' => $instance->id,
            'workflow_instance_step_id' => $step?->id, 'event' => $event, 'status' => $status, 'detail' => mb_substr($detail, 0, 490)]);
    }

    /** Whether a step was escalated to this user (directly or through a role). */
    public function escalatedTo(User $user, WorkflowInstanceStep $step): bool
    {
        if ($step->escalated_user_id && $step->escalated_user_id === $user->id) {
            return true;
        }

        return $step->escalated_role_id && ($role = Role::find($step->escalated_role_id)) && in_array($role->name, $this->access->roles($user), true);
    }
}
