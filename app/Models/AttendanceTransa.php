<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AttendanceTransa extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'employee_id', 'contract_id', 'timetable_detail',
        'transaction_date', 'pass_day', 'attendance_in_time', 'punch_in_time',
        'punch_out_time', 'attendance_out_time', 'punch_in_time_loss', 'punch_out_time_loss',
        'worktime_hour', 'worked_hour', 'basic_salary', 'amount_per_day', 'amount_earned_per_day',
        'actual_hour', 'deduct_leave', 'lost_hour', 'lost_amount', 'percent',
        'punch_out_status', 'punch_in_status', 'punch_in_late_minutes', 'punch_out_late_minutes',
        'overtime_hr', 'overtime_minutes', 'has_overtime', 'is_claimed', 'description',
        'status', 'area_alias', 'in_area_alias', 'punch_in_late_hour', 'punch_out_late_hour', 'payroll_date_remark', 'planned_code', 'accepted_hour',
    ];

    protected $casts = [
        'pass_day' => 'boolean', 'deduct_leave' => 'boolean', 'has_overtime' => 'boolean', 'is_claimed' => 'boolean', 'transaction_date' => 'date',
        'worktime_hour' => 'float', 'worked_hour' => 'float', 'lost_hour' => 'float', 'lost_amount' => 'float', 'accepted_hour' => 'float', 'percent' => 'float',
        'amount_per_day' => 'float', 'basic_salary' => 'float',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function contract()
    {
        return $this->belongsTo(EmployeeContract::class);
    }

    public function claims()
    {
        return $this->hasMany(AttendanceClaim::class, 'attendance_transa_id');
    }
}
