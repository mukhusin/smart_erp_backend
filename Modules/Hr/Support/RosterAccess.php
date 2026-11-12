<?php

namespace Modules\Hr\Support;

use App\Models\Department;
use App\Models\Roster;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Services\AccessService;

/**
 * Who may see and prepare duty rosters: `roster.view` sees all; `roster.manage` prepares rosters of the departments and
 * sections the user can see employees of (HR: all; head of department: their department; section head: their section).
 */
class RosterAccess
{
    public function __construct(private readonly AccessService $access, private readonly EmployeeScope $scope) {}

    public function canList(User $user): bool
    {
        return collect(['roster.view', 'roster.manage', 'roster.department.view', 'roster.section.view'])->contains(fn ($p) => $this->access->can($user, $p));
    }

    public function canView(User $user, Roster $roster): bool
    {
        return $this->access->can($user, 'roster.view') || ($this->canList($user) && $this->covers($user, $roster->department_id, $roster->section_id));
    }

    public function canManage(User $user, int $departmentId, ?int $sectionId): bool
    {
        return $this->access->can($user, 'roster.manage') && $this->covers($user, $departmentId, $sectionId);
    }

    public function covers(User $user, int $departmentId, ?int $sectionId): bool
    {
        $rules = $this->scope->rules($user);
        if ($rules === null) {
            return true;
        }
        if (in_array($departmentId, $rules['departments'], true) || ($sectionId && in_array($sectionId, $rules['departments'], true))) {
            return true;
        }
        if ($sectionId && in_array($sectionId, $rules['sections'], true)) {
            return true;
        }
        $branch = Department::withTrashed()->whereKey($departmentId)->value('company_branch_id');

        return $branch && in_array((int) $branch, $rules['branches'], true);
    }

    public function apply(Builder $query, User $user): Builder
    {
        $rules = $this->access->can($user, 'roster.view') ? null : $this->scope->rules($user);
        if ($rules === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($rules) {
            $q->whereRaw('1 = 0');
            if ($rules['departments']) {
                $q->orWhereIn('department_id', $rules['departments'])->orWhereIn('section_id', $rules['departments']);
            }
            if ($rules['sections']) {
                $q->orWhereIn('section_id', $rules['sections']);
            }
            if ($rules['branches']) {
                $q->orWhereHas('department', fn ($d) => $d->whereIn('company_branch_id', $rules['branches']));
            }
        });
    }

    /** Departments and sections the user may prepare rosters for. */
    public function manageable(User $user): Builder
    {
        $rules = $this->scope->rules($user);
        $query = Department::query()->where('is_active', true);
        if ($rules === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($rules) {
            $q->whereRaw('1 = 0');
            if ($rules['departments']) {
                $q->orWhereIn('id', $rules['departments'])->orWhereIn('parent_id', $rules['departments']);
            }
            if ($rules['sections']) {
                $q->orWhereIn('id', $rules['sections']);
            }
            if ($rules['branches']) {
                $q->orWhereIn('company_branch_id', $rules['branches']);
            }
        });
    }
}
