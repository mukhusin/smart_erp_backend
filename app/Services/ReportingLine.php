<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmpSupervisor;
use App\Models\HeadOfDepartment;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Who an employee reports to: the supervisor assigned on their profile, otherwise the head of their department
 * (the head of department has no default supervisor). Not stored, so a new head takes over at once.
 */
class ReportingLine
{
    public const ASSIGNED = 'assigned';

    public const DEPARTMENT_HEAD = 'department_head';

    /**
     * `title`: what to show. The person's name when assigned; the role ("Head of Nursing") for the default.
     *
     * @return array{employee: Employee, source: string, title: string, since: ?CarbonInterface}|null
     */
    public function supervisorOf(Employee $employee): ?array
    {
        $assigned = EmpSupervisor::with('supervisor')->where('employee_id', $employee->id)->where('status', 'active')->latest('id')->first();
        if ($assigned?->supervisor) {
            return ['employee' => $assigned->supervisor, 'source' => self::ASSIGNED, 'title' => $assigned->supervisor->full_name, 'since' => $assigned->from_date];
        }

        $head = $this->headOf($employee);

        return $head ? [
            'employee' => $head->employee, 'source' => self::DEPARTMENT_HEAD, 'title' => 'Head of '.$head->department?->name,
            'since' => $head->started_at ?? $head->created_at,
        ] : null;
    }

    public function supervisorIdOf(int $employeeId): ?int
    {
        $assigned = EmpSupervisor::where('employee_id', $employeeId)->where('status', 'active')->latest('id')->value('supervisor_id');
        if ($assigned) {
            return (int) $assigned;
        }
        $employee = Employee::withoutGlobalScopes()->find($employeeId);

        return $employee ? $this->headOf($employee)?->employee_id : null;
    }

    /** Whether $boss is above $person in the reporting line. */
    public function isAbove(int $bossId, int $personId): bool
    {
        $seen = [];
        $id = $personId;
        while ($id && ! isset($seen[$id])) {
            $seen[$id] = true;
            $id = $this->supervisorIdOf($id);
            if ($id === $bossId) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, array{employee: Employee, source: string, since: ?CarbonInterface}> */
    public function teamOf(Employee $boss): Collection
    {
        $assigned = EmpSupervisor::with('employee.jobPosition')->where('supervisor_id', $boss->id)->where('status', 'active')->get()
            ->filter(fn ($s) => $s->employee && $s->employee->employment_status !== 'terminated')
            ->map(fn ($s) => ['employee' => $s->employee, 'source' => self::ASSIGNED, 'since' => $s->from_date]);

        $headed = HeadOfDepartment::where('employee_id', $boss->id)->where('level', HeadOfDepartment::DEPARTMENT)->where('is_active', true)->get();
        $byDepartment = $headed->isEmpty() ? collect() : Employee::withoutGlobalScopes()->with('jobPosition')
            ->where('company_id', $boss->company_id)->whereNull('deleted_at')
            ->whereIn('department_id', $headed->pluck('department_id'))
            ->where('employment_status', '!=', 'terminated')->whereKeyNot($boss->id)
            ->whereDoesntHave('supervisors')->get()
            ->map(fn ($e) => ['employee' => $e, 'source' => self::DEPARTMENT_HEAD, 'since' => $headed->firstWhere('department_id', $e->department_id)?->started_at]);

        return $assigned->concat($byDepartment)->unique(fn ($r) => $r['employee']->id)->sortBy(fn ($r) => $r['employee']->fname)->values();
    }

    private function headOf(Employee $employee): ?HeadOfDepartment
    {
        if (! $employee->department_id) {
            return null;
        }
        $head = HeadOfDepartment::with('employee', 'department')->where('department_id', $employee->department_id)
            ->where('level', HeadOfDepartment::DEPARTMENT)->where('is_active', true)->latest('id')->first();

        return $head && $head->employee && (int) $head->employee_id !== $employee->id ? $head : null;
    }
}
