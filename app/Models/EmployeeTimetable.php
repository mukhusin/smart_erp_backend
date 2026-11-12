<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** One planned day of an employee: a roster duty (source roster) or a leave day (source leave). */
class EmployeeTimetable extends Model
{
    use BelongsToCompany;

    public const ROSTER = 'roster';

    public const LEAVE = 'leave';

    protected $fillable = [
        'company_id', 'employee_id', 'roster_id', 'roster_code_id', 'day_date', 'code', 'duty', 'can_punch', 'pass_day',
        'start_time', 'end_time', 'break_minutes', 'work_hour', 'color', 'source', 'source_id', 'overtime', 'max_overtime_hours', 'updated_by',
    ];

    protected $casts = [
        'day_date' => 'date', 'can_punch' => 'boolean', 'pass_day' => 'boolean', 'overtime' => 'boolean',
        'work_hour' => 'float', 'break_minutes' => 'integer', 'max_overtime_hours' => 'float',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function roster()
    {
        return $this->belongsTo(Roster::class);
    }

    public function rosterCode()
    {
        return $this->belongsTo(RosterCode::class);
    }

    /** Copies a duty code onto the day, so later changes to the code do not change rostered days. */
    public function fillFromCode(RosterCode $code): static
    {
        return $this->fill([
            'roster_code_id' => $code->id, 'code' => $code->code, 'duty' => $code->duty, 'can_punch' => $code->can_punch, 'pass_day' => $code->pass_day,
            'start_time' => $code->start_time, 'end_time' => $code->end_time, 'break_minutes' => (int) $code->break_minutes,
            'work_hour' => (float) $code->work_hour, 'color' => $code->color,
        ]);
    }
}
