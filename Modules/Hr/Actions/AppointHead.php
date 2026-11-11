<?php

namespace Modules\Hr\Actions;

use App\Models\Department;
use App\Models\Employee;
use App\Models\HeadOfDepartment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Actions\ChangeUserAccess;
use Modules\Core\Support\DefaultRoles;
use Spatie\Permission\Models\Role;

/**
 * One active head per department (level `hod`) or section (level `hos`), as in HRMS. A change keeps the old head
 * in the history. The new head's account gets the head role (`hod` / `section-head`); the previous head loses it
 * unless they still head another department or section of that level. Role changes go through ChangeUserAccess,
 * so they are logged and follow the company's user-access approval workflow.
 */
class AppointHead
{
    public function __construct(private readonly ChangeUserAccess $access) {}

    /** @return list<string> warnings for HR */
    public function appoint(Department $department, Employee $employee, ?string $reason, ?User $by): array
    {
        if ($employee->employment_status === 'terminated') {
            throw ValidationException::withMessages(['employee_id' => 'This employee has left the organization.']);
        }
        $current = $department->headOfDepartment()->first();
        if ($current && (int) $current->employee_id === $employee->id) {
            throw ValidationException::withMessages(['employee_id' => 'This employee is already the head.']);
        }

        $level = $department->parent_id ? HeadOfDepartment::SECTION : HeadOfDepartment::DEPARTMENT;
        DB::transaction(function () use ($department, $employee, $reason, $by, $current, $level) {
            $current?->update(['is_active' => false, 'ended_at' => now()->toDateString(), 'change_reason' => $reason]);
            $department->heads()->create([
                'employee_id' => $employee->id, 'level' => $level, 'started_at' => now()->toDateString(),
                'change_reason' => $reason, 'appointed_by' => $by?->id, 'is_active' => true,
            ]);
        });

        $warnings = array_filter([
            $this->syncRole($employee, $level, "Appointed head of {$department->name}"),
            $current ? $this->syncRole($current->employee, $level, "No longer head of {$department->name}") : null,
        ]);

        return array_values($warnings);
    }

    /** @return list<string> */
    public function remove(Department $department, ?string $reason): array
    {
        $current = $department->headOfDepartment()->first();
        if (! $current) {
            return [];
        }
        $current->update(['is_active' => false, 'ended_at' => now()->toDateString(), 'change_reason' => $reason]);

        return array_values(array_filter([$this->syncRole($current->employee, $current->level, "No longer head of {$department->name}")]));
    }

    private function ensureRole(string $name): void
    {
        if (! Role::where('name', $name)->where('guard_name', 'web')->exists()) {
            $role = Role::findOrCreate($name, 'web');
            $role->forceFill(['is_system' => true])->save();
            $role->syncPermissions(DefaultRoles::expand(DefaultRoles::ROLES[$name] ?? []));
        }
    }

    /** Gives or removes the head role to match whether the employee still heads anything at this level. */
    private function syncRole(?Employee $employee, string $level, string $reason): ?string
    {
        $user = $employee?->user;
        $role = DefaultRoles::HEAD_ROLES[$level] ?? null;
        if (! $user || ! $role) {
            return $employee && ! $user ? "{$employee->full_name} has no user account, so no head role was given." : null;
        }

        setPermissionsTeamId($user->company_id);
        $this->ensureRole($role);
        $heads = HeadOfDepartment::where('employee_id', $employee->id)->where('level', $level)->where('is_active', true)->exists();
        $roles = $user->getRoleNames()->all();
        $wanted = $heads ? array_values(array_unique([...$roles, $role])) : array_values(array_diff($roles, [$role]));
        if ($wanted === []) {
            $wanted = ['employee'];
        }
        if (collect($wanted)->sort()->values()->all() === collect($roles)->sort()->values()->all()) {
            return null;
        }

        try {
            $change = $this->access->setRoles($user, $wanted, $reason);
        } catch (ValidationException $e) {
            return "{$employee->full_name}: role not changed. ".collect($e->errors())->flatten()->first();
        }

        return $change->status === 'pending' ? "{$employee->full_name}: the role change is waiting for approval." : null;
    }
}
