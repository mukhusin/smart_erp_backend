<?php

namespace Modules\Hr\Support;

use App\Models\Department;
use App\Models\HeadOfDepartment;
use App\Models\User;

/** What a user heads (as head or deputy): departments and sections. */
class Heads
{
    /** @var array<int, array{departments: list<int>, sections: list<int>}> */
    private array $cache = [];

    /** @return array{departments: list<int>, sections: list<int>} */
    public function of(User $user): array
    {
        if (isset($this->cache[$user->id])) {
            return $this->cache[$user->id];
        }
        $me = $user->employee;
        $rows = $me ? HeadOfDepartment::where('employee_id', $me->id)->where('is_active', true)->get(['department_id', 'level']) : collect();

        return $this->cache[$user->id] = [
            'departments' => $rows->where('level', HeadOfDepartment::DEPARTMENT)->pluck('department_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'sections' => $rows->where('level', HeadOfDepartment::SECTION)->pluck('department_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
        ];
    }

    public function isHead(User $user): bool
    {
        $h = $this->of($user);

        return $h['departments'] !== [] || $h['sections'] !== [];
    }

    /** Names for display, e.g. "Nursing" or "Ward A (Nursing)". @return list<string> */
    public function names(User $user): array
    {
        $h = $this->of($user);

        return Department::withTrashed()->with('parent')->whereIn('id', [...$h['departments'], ...$h['sections']])->orderBy('name')->get()
            ->map(fn ($d) => $d->parent ? "{$d->name} ({$d->parent->name})" : $d->name)->all();
    }
}
