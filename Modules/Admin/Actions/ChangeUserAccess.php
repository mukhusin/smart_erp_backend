<?php

namespace Modules\Admin\Actions;

use App\Models\User;
use App\Services\SingleDeviceSessionManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\AccessChange;
use Modules\Core\Services\AccessService;
use Modules\Core\Support\DefaultRoles;
use Modules\Workflow\Services\WorkflowEngine;
use Spatie\Permission\Models\Role;

/**
 * The only way a company admin changes a user's access: roles, direct permissions, activation.
 *
 * Each change is recorded in access_changes. If the company configured an approval workflow for
 * "user-access-change", the change stays pending and is applied by apply() when the workflow approves it.
 * Without a workflow it is applied at once.
 */
class ChangeUserAccess
{
    public const PROCESS = 'user-access-change';

    public function __construct(
        private readonly AccessService $access,
        private readonly SingleDeviceSessionManager $sessions,
        private readonly WorkflowEngine $workflow,
    ) {}

    /** @param list<string> $roleNames */
    public function setRoles(User $user, array $roleNames, ?string $reason = null): AccessChange
    {
        $before = $user->getRoleNames()->sort()->values()->all();
        $after = collect($roleNames)->unique()->sort()->values()->all();
        $this->checkRoles($user, $before, $after);

        return $this->request($user, 'roles', $before, $after, $reason);
    }

    /** @param list<string> $permissions */
    public function setDirectPermissions(User $user, array $permissions, ?string $reason = null): AccessChange
    {
        $before = $user->getDirectPermissions()->pluck('name')->sort()->values()->all();
        $after = collect($permissions)->unique()->sort()->values()->all();

        return $this->request($user, 'permissions', $before, $after, $reason);
    }

    public function deactivate(User $user, string $reason): AccessChange
    {
        $this->checkDeactivate($user);

        return $this->request($user, 'deactivate', ['is_active' => true], ['is_active' => false], $reason);
    }

    /**
     * The employee left (HR ended their contract): deactivate at once, without the approval workflow
     * (HR already confirmed the termination). Recorded in access_changes with the reason.
     * Returns false when the user is the only active company admin (the account is kept).
     */
    public function deactivateLeaver(User $user, string $reason): bool
    {
        if (! $user->is_active) {
            return true;
        }
        setPermissionsTeamId($user->company_id);
        if ($user->hasRole(DefaultRoles::COMPANY_ADMIN)) {
            try {
                $this->ensureAnotherAdmin($user);
            } catch (ValidationException) {
                return false;
            }
        }

        DB::transaction(function () use ($user, $reason) {
            AccessChange::create([
                'company_id' => $user->company_id,
                'user_id' => $user->id,
                'type' => 'deactivate',
                'before' => ['is_active' => true],
                'after' => ['is_active' => false],
                'reason' => $reason,
                'status' => AccessChange::APPLIED,
                'requested_by' => Auth::id(),
                'decided_by' => Auth::id(),
                'applied_at' => now(),
            ]);
            $this->doDeactivate($user);
            $this->access->forget($user);
        });

        return true;
    }

    public function activate(User $user, ?string $reason = null): AccessChange
    {
        if ($user->is_active) {
            throw ValidationException::withMessages(['user' => 'This user is already active.']);
        }

        return $this->request($user, 'activate', ['is_active' => false], ['is_active' => true], $reason);
    }

    /** Applies a recorded change (at once, or when its approval workflow finishes). Rules are checked again. */
    public function apply(AccessChange $change): void
    {
        DB::transaction(function () use ($change) {
            $user = $change->user()->firstOrFail();
            setPermissionsTeamId($user->company_id);

            match ($change->type) {
                'roles' => $this->applyRoles($user, $change->after),
                'permissions' => $user->syncPermissions($change->after),
                'deactivate' => $this->doDeactivate($user),
                'activate' => $user->forceFill(['is_active' => true])->save(),
            };

            $change->update(['status' => AccessChange::APPLIED, 'applied_at' => now(), 'decided_by' => Auth::id()]);
            $this->access->forget($user);
        });
    }

    private function request(User $user, string $type, array $before, array $after, ?string $reason): AccessChange
    {
        if (AccessChange::where('user_id', $user->id)->where('status', AccessChange::PENDING)->exists()) {
            throw ValidationException::withMessages(['user' => 'A change for this user is waiting for approval. Wait for it to be decided first.']);
        }

        return DB::transaction(function () use ($user, $type, $before, $after, $reason) {
            $change = AccessChange::create([
                'company_id' => $user->company_id,
                'user_id' => $user->id,
                'type' => $type,
                'before' => $before,
                'after' => $after,
                'reason' => $reason,
                'status' => AccessChange::PENDING,
                'requested_by' => Auth::id(),
            ]);

            $instance = $this->workflow->start(self::PROCESS, $change, Auth::user());
            if (! $instance) {
                $this->apply($change);
            }

            return $change->refresh();
        });
    }

    private function checkRoles(User $user, array $before, array $after): void
    {
        if (in_array(DefaultRoles::COMPANY_ADMIN, $before, true) && ! in_array(DefaultRoles::COMPANY_ADMIN, $after, true)) {
            $this->ensureAnotherAdmin($user);
        }
    }

    private function applyRoles(User $user, array $roles): void
    {
        $this->checkRoles($user, $user->getRoleNames()->all(), $roles);
        $user->syncRoles($roles);
    }

    private function checkDeactivate(User $user): void
    {
        if ($user->id === Auth::id()) {
            throw ValidationException::withMessages(['user' => 'You cannot deactivate your own account.']);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['user' => 'This user is already inactive.']);
        }
        if ($user->hasRole(DefaultRoles::COMPANY_ADMIN)) {
            $this->ensureAnotherAdmin($user);
        }
    }

    private function doDeactivate(User $user): void
    {
        $user->forceFill(['is_active' => false])->save();
        // End the user's current session immediately (single-device registry) and any API tokens.
        $this->sessions->clearSessionRegistration($user->id);
        $user->tokens()->delete();
    }

    /** A company must always keep at least one active company admin. */
    private function ensureAnotherAdmin(User $user): void
    {
        $role = Role::where('name', DefaultRoles::COMPANY_ADMIN)->where('company_id', $user->company_id)->first();
        $others = $role?->users()->where('users.id', '!=', $user->id)->where('is_active', true)->count() ?? 0;
        if ($others === 0) {
            throw ValidationException::withMessages(['user' => 'This is the only company admin. Give another user the company-admin role first.']);
        }
    }
}
