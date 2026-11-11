<?php

namespace Modules\Hr\Support;

use App\Models\Department;
use App\Models\Employee;
use App\Models\HeadOfDepartment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Services\AccessService;

/**
 * Which employees a user may see (HRMS: HR office sees all, `department-office` its department,
 * `department-section` its section). Permissions combine: the user sees the union of their scopes.
 */
class EmployeeScope
{
    public const ALL = 'hr.employees.view';

    public const BRANCH = 'hr.employees.view-branch';

    public const DEPARTMENT = 'hr.employees.view-department';

    public const SECTION = 'hr.employees.view-section';

    /** @var array<int, array{branches: list<int>, departments: list<int>, sections: list<int>}|null> */
    private array $cache = [];

    public function __construct(private readonly AccessService $access) {}

    public function canBrowse(User $user): bool
    {
        return collect([self::ALL, self::BRANCH, self::DEPARTMENT, self::SECTION])->contains(fn ($p) => $this->access->can($user, $p));
    }

    /** @return array{branches: list<int>, departments: list<int>, sections: list<int>}|null null = every employee */
    public function rules(User $user): ?array
    {
        if (array_key_exists($user->id, $this->cache)) {
            return $this->cache[$user->id];
        }
        if ($this->access->can($user, self::ALL)) {
            return $this->cache[$user->id] = null;
        }

        $me = $user->employee;
        $rules = ['branches' => [], 'departments' => [], 'sections' => []];
        if ($this->access->can($user, self::BRANCH) && ($user->company_branch_id || $me?->company_branch_id)) {
            $rules['branches'][] = (int) ($user->company_branch_id ?: $me->company_branch_id);
        }
        if ($this->access->can($user, self::DEPARTMENT) && $me) {
            $headed = HeadOfDepartment::where('employee_id', $me->id)->where('is_active', true)->pluck('department_id')->map(fn ($id) => (int) $id);
            $rules['departments'] = $headed->push((int) $me->department_id)->filter()->unique()->values()->all();
        }
        if ($this->access->can($user, self::SECTION) && $me?->section_id) {
            $rules['sections'][] = (int) $me->section_id;
        }

        return $this->cache[$user->id] = $rules;
    }

    public function apply(Builder $query, User $user): Builder
    {
        $rules = $this->rules($user);
        if ($rules === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($rules) {
            $q->whereRaw('1 = 0');
            if ($rules['branches']) {
                $q->orWhereIn('company_branch_id', $rules['branches']);
            }
            if ($rules['departments']) {
                $q->orWhereIn('department_id', $rules['departments'])->orWhereIn('section_id', $rules['departments']);
            }
            if ($rules['sections']) {
                $q->orWhereIn('section_id', $rules['sections']);
            }
        });
    }

    public function allows(User $user, Employee $employee): bool
    {
        return $this->apply(Employee::whereKey($employee->id), $user)->exists();
    }

    /** @return array{all: bool, label: string} */
    public function describe(User $user): array
    {
        $rules = $this->rules($user);
        if ($rules === null) {
            return ['all' => true, 'label' => 'All employees'];
        }
        $names = Department::whereIn('id', array_merge($rules['departments'], $rules['sections']))->orderBy('name')->pluck('name');
        $parts = $names->all();
        if ($rules['branches']) {
            $parts[] = 'your branch';
        }

        return ['all' => false, 'label' => $parts ? 'Employees of '.implode(', ', $parts) : 'No employees'];
    }
}
