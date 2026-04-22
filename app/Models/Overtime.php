<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Overtime extends Model
{
    protected $fillable = [
        'company_id', 'employee_id', 'department_id', 'section_id',
        'hod_approved', 'hr_approved', 'is_hod_approved', 'is_hr_approved',
        'is_paid', 'hours', 'minutes', 'amount_per_minute', 'amount',
        'task_title', 'date_of_task_done', 'from_datetime', 'to_datetime',
        'type', 'description', 'hod_comment', 'hr_comment', 'status', 'is_active',
    ];

    protected $casts = [
        'is_hod_approved' => 'boolean', 'is_hr_approved' => 'boolean',
        'is_paid' => 'boolean', 'is_active' => 'boolean',
    ];

    public function employee()   { return $this->belongsTo(Employee::class); }
    public function department() { return $this->belongsTo(Department::class); }
    public function section()    { return $this->belongsTo(Department::class, 'section_id'); }
    public function hodApprover(){ return $this->belongsTo(Employee::class, 'hod_approved'); }
    public function hrApprover() { return $this->belongsTo(Employee::class, 'hr_approved'); }
    public function company()    { return $this->belongsTo(Company::class); }
}
