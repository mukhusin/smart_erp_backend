<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RosterTimetable extends Model
{
    protected $fillable = [
        'company_id', 'department_id', 'section_id', 'title', 'work_hour',
        'check_in_start', 'check_in_end', 'check_out_start', 'check_out_end',
        'cross_day', 'approved_by', 'prepared_by', 'status', 'use_attendance', 'is_active',
    ];

    protected $casts = ['use_attendance' => 'boolean', 'is_active' => 'boolean'];

    public function company()    { return $this->belongsTo(Company::class); }
    public function department() { return $this->belongsTo(Department::class); }
    public function section()    { return $this->belongsTo(Department::class, 'section_id'); }
    public function approvedBy() { return $this->belongsTo(Employee::class, 'approved_by'); }
    public function preparedBy() { return $this->belongsTo(Employee::class, 'prepared_by'); }
}
