<?php

namespace Modules\Hr\Attendance;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Registers employees in BioTime (as HRMS did, straight into its database): department and position by name, the
 * employee by emp_code (the employee's attendance code) and access to every device area. Fingerprints are enrolled
 * on the device; this only makes the person known to it.
 */
class BiotimePersonnel
{
    public function __construct(private readonly string $connection) {}

    private function db(): \Illuminate\Database\Connection
    {
        return DB::connection($this->connection);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return $this->db()->table('personnel_employee')->pluck('emp_code')->map(fn ($c) => (string) $c)->all();
    }

    public function has(string $code): bool
    {
        return $this->db()->table('personnel_employee')->where('emp_code', $code)->exists();
    }

    /** @return 'added'|'updated' */
    public function save(Employee $employee, string $code): string
    {
        $employee->loadMissing('department', 'section', 'jobPosition');
        $department = $this->department($employee->section ?? $employee->department);
        $position = $employee->jobPosition ? $this->position($employee->jobPosition->title) : null;
        $gender = ['female' => 'F', 'male' => 'M'][strtolower((string) $employee->gender)] ?? 'S';
        $values = [
            'first_name' => trim(preg_replace('/\s+/', ' ', "{$employee->fname} {$employee->mname} {$employee->lname}")), 'last_name' => (string) $employee->lname,
            'nickname' => $employee->mname, 'department_id' => $department, 'position_id' => $position, 'hire_date' => $employee->employment_date?->toDateString(),
            'gender' => $gender, 'birthday' => $employee->date_of_birth?->toDateString(), 'national' => $employee->nationality, 'email' => $employee->email,
            'update_time' => now()->toDateTimeString(),
        ];

        $existing = $this->db()->table('personnel_employee')->where('emp_code', $code)->first();
        if ($existing) {
            $this->db()->table('personnel_employee')->where('id', $existing->id)->update($values);
            $this->areas((int) $existing->id);

            return 'updated';
        }
        $id = $this->db()->table('personnel_employee')->insertGetId($values + [
            'emp_code' => $code, 'verify_mode' => 1, 'dev_privilege' => 0, 'app_status' => 0, 'app_role' => 1,
        ]);
        $this->areas((int) $id);

        return 'added';
    }

    private function department(?\App\Models\Department $d): ?int
    {
        if (! $d) {
            return $this->db()->table('personnel_department')->orderBy('id')->value('id');
        }
        $found = $this->db()->table('personnel_department')->where('dept_name', $d->name)->value('id');

        return $found ? (int) $found : (int) $this->db()->table('personnel_department')->insertGetId(['dept_code' => 'E'.$d->id, 'dept_name' => $d->name, 'parent_dept_id' => null]);
    }

    private function position(string $title): int
    {
        $found = $this->db()->table('personnel_position')->where('position_name', $title)->value('id');

        return $found ? (int) $found : (int) $this->db()->table('personnel_position')->insertGetId(['position_code' => substr('E'.md5($title), 0, 20), 'position_name' => $title, 'parent_position_id' => null]);
    }

    private function areas(int $employeeId): void
    {
        $have = $this->db()->table('personnel_employee_area')->where('employee_id', $employeeId)->pluck('area_id')->all();
        foreach ($this->db()->table('personnel_area')->pluck('id') as $area) {
            if (! in_array($area, $have)) {
                $this->db()->table('personnel_employee_area')->insert(['employee_id' => $employeeId, 'area_id' => $area]);
            }
        }
    }
}
