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
use Spatie\Permission\Models\Role;

/**
 * The only way a company admin changes a user's access: roles, direct permissions, activation.
 * Records an AccessChange, then applies it. When approval is introduced, record() will create a
 * pending change and apply() will run after approval; callers and the API stay the same.
 */
class ChangeUserAccess
{
    public function __construct(
        private readonly AccessService $access,
        private readonly SingleDeviceSessionManager $sessions,
    ) {}

    /** @param list<string> $roleNames */
    public function setRoles(User $user, array $roleNames, ?string $reason = null): AccessChange
    {
        $before = $user->getRoleNames()->sort()->values()->all();
        $after = collect($roleNames)->unique()->sort()->values()->all();

        if (in_array(DefaultRoles::COMPANY_ADMIN, $before, true) && ! in_array(DefaultRoles::COMPANY_ADMIN, $after, true)) {
            $this->ensureAnotherAdmin($user);
        }

        return $this->record($user, 'roles', $before, $after, $reason, fn () => $user->syncRoles($after));
    }

    /** @param list<string> $permissions */
    public function setDirectPermissions(User $user, array $permissions, ?string $reason = null): AccessChange
    {
        $before = $user->getDirectPermissions()->pluck('name')->sort()->values()->all();
        $after = collect($permissions)->unique()->sort()->values()->all();

        return $this->record($user, 'permissions', $before, $after, $reason, fn () => $user->syncPermissions($after));
    }

    public function deactivate(User $user, string $reason): AccessChange
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

        return $this->record($user, 'deactivate', ['is_active' => true], ['is_active' => false], $reason, function () use ($user) {
            $user->forceFill(['is_active' => false])->save();
            // End the user's current session immediately (single-device registry).
            $this->sessions->clearSessionRegistration($user->id);
            $user->tokens()->delete();
        });
    }

    public function activate(User $user, ?string $reason = null): AccessChange
    {
        if ($user->is_active) {
            throw ValidationException::withMessages(['user' => 'This user is already active.']);
        }

        return $this->record($user, 'activate', ['is_active' => false], ['is_active' => true], $reason,
            fn () => $user->forceFill(['is_active' => true])->save());
    }

    private function record(User $user, string $type, array $before, array $after, ?string $reason, \Closure $apply): AccessChange
    {
        return DB::transaction(function () use ($user, $type, $before, $after, $reason, $apply) {
            $change = AccessChange::create([
                'company_id' => $user->company_id,
                'user_id' => $user->id,
                'type' => $type,
                'before' => $before,
                'after' => $after,
                'reason' => $reason,
                'status' => AccessChange::APPLIED,
                'requested_by' => Auth::id(),
                'decided_by' => Auth::id(),
                'applied_at' => now(),
            ]);
            $apply();
            $this->access->forget($user);

            return $change;
        });
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
