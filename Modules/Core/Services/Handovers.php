<?php

namespace Modules\Core\Services;

use App\Models\EmpLeave;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\RoleHandover;
use Modules\Core\Notifications\Notice;
use Modules\Core\Workflow\RoleHandoverProcess;
use Modules\Workflow\Services\WorkflowEngine;
use Spatie\Permission\Models\Role;

/**
 * Role handovers made by employees: from Self-service (approved in the "Role handover" workflow) or with a leave
 * request (in effect for the leave's days once the leave is approved). The giver can be suspended meanwhile.
 */
class Handovers
{
    public function __construct(private readonly AccessService $access, private readonly WorkflowEngine $workflow) {}

    /** Roles the user holds and may hand over themselves. @return Collection<int, Role> */
    public function ownRoles(User $user): Collection
    {
        $ids = DB::table(config('permission.table_names.model_has_roles'))->where('model_type', $user->getMorphClass())->where('model_id', $user->id)
            ->where('company_id', $user->company_id)->pluck('role_id');

        return Role::whereIn('id', $ids)->whereNotIn('name', RoleHandover::NOT_SELF)->orderBy('name')->get(['id', 'name']);
    }

    /** @param array{role_id: int, to_user_id: int, starts_at: string, ends_at: string, reason: string, suspend_giver?: bool} $data */
    public function request(User $giver, array $data): RoleHandover
    {
        $role = $this->ownRoles($giver)->firstWhere('id', (int) $data['role_id']);
        if (! $role) {
            throw ValidationException::withMessages(['role_id' => 'Choose a role you hold.']);
        }
        $to = $this->receiver($giver, (int) $data['to_user_id']);
        $handover = RoleHandover::create([
            'role_id' => $role->id, 'from_user_id' => $giver->id, 'to_user_id' => $to->id, 'starts_at' => Carbon::parse($data['starts_at'])->startOfDay(),
            'ends_at' => Carbon::parse($data['ends_at'])->addDay()->startOfDay(), 'reason' => $data['reason'], 'status' => 'pending', 'source' => 'self',
            'suspend_giver' => $data['suspend_giver'] ?? true, 'created_by' => $giver->id,
        ]);
        if (! $this->workflow->start(RoleHandoverProcess::KEY, $handover, $giver)) {
            $this->approve($handover);
        }

        return $handover->refresh();
    }

    /** Hands every own role to the colleague for the leave's days; in effect once the leave is approved. */
    public function forLeave(EmpLeave $leave, User $giver, int $toUserId): void
    {
        $roles = $this->ownRoles($giver);
        if ($roles->isEmpty()) {
            throw ValidationException::withMessages(['handover_to_user_id' => 'You have no roles to hand over.']);
        }
        $to = $this->receiver($giver, $toUserId);
        foreach ($roles as $role) {
            RoleHandover::create([
                'role_id' => $role->id, 'from_user_id' => $giver->id, 'to_user_id' => $to->id, 'starts_at' => $leave->start_date->copy()->startOfDay(),
                'ends_at' => $leave->end_date->copy()->addDay()->startOfDay(), 'reason' => 'Covering during leave', 'status' => 'pending', 'source' => 'leave',
                'suspend_giver' => true, 'emp_leave_id' => $leave->id, 'created_by' => $giver->id,
            ]);
        }
        $this->syncLeave($leave);
    }

    /** Keeps the handovers of a leave in step with it (approved, dates changed, came back early, not approved). */
    public function syncLeave(EmpLeave $leave): void
    {
        $handovers = RoleHandover::where('emp_leave_id', $leave->id)->whereNull('revoked_at')->get();
        foreach ($handovers as $h) {
            $ends = ($leave->report_date ?? $leave->end_date->copy()->addDay())->copy()->startOfDay();
            $status = match ($leave->status) {
                EmpLeave::PENDING => 'pending',
                EmpLeave::APPROVED, EmpLeave::ON_LEAVE, EmpLeave::REPORTED => 'approved',
                default => 'cancelled',
            };
            $wasApproved = $h->status === 'approved';
            $h->update(['status' => $status, 'starts_at' => $leave->start_date->copy()->startOfDay(), 'ends_at' => $ends, 'decided_at' => $status === 'pending' ? null : ($h->decided_at ?? now())]);
            if ($status === 'approved' && ! $wasApproved) {
                $this->notifyReceiver($h);
            }
        }
        $this->access->forget();
    }

    public function approve(RoleHandover $handover): void
    {
        $handover->update(['status' => 'approved', 'decided_at' => now()]);
        $this->access->forget();
        $this->notifyReceiver($handover);
    }

    public function reject(RoleHandover $handover, ?string $comment): void
    {
        $withdrawn = $comment === 'Withdrawn by the requester.';
        $handover->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
    }

    /** The giver withdraws a request or ends a handover early. */
    public function cancel(RoleHandover $handover, User $by): RoleHandover
    {
        abort_unless($handover->from_user_id === $by->id, 404);
        $state = $handover->state();
        if ($state === 'pending' && $handover->emp_leave_id) {
            throw ValidationException::withMessages(['handover' => 'This handover follows your leave request; it starts only if the leave is approved.']);
        }
        if ($state === 'pending') {
            if ($instance = $handover->workflow()->where('status', 'pending')->first()) {
                $this->workflow->cancel($instance, $by);
            } else {
                $this->reject($handover, 'Withdrawn by the requester.');
            }
        } elseif (in_array($state, ['active', 'upcoming'], true)) {
            $handover->update(['revoked_at' => now(), 'revoked_by' => $by->id]);
            $this->access->forget();
        } else {
            throw ValidationException::withMessages(['handover' => 'This handover has already ended.']);
        }

        return $handover->refresh();
    }

    private function receiver(User $giver, int $id): User
    {
        $to = User::where('company_id', $giver->company_id)->where('is_active', true)->find($id);
        if (! $to || $to->id === $giver->id) {
            throw ValidationException::withMessages(['to_user_id' => 'Choose an active colleague.']);
        }

        return $to;
    }

    private function notifyReceiver(RoleHandover $h): void
    {
        $h->loadMissing('role', 'fromUser', 'toUser');
        $h->toUser?->notify(new Notice('You are covering a role', ':from handed over :role to you from :start to :end.', '/self-service/handovers', 'person-badge',
            params: ['from' => $h->fromUser?->display_name ?? 'A colleague', 'role' => $h->role?->name, 'start' => $h->starts_at->format('d M Y'), 'end' => $h->ends_at?->copy()->subDay()->format('d M Y') ?? '…']));
    }
}
