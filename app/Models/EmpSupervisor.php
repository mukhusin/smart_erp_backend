<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Who supervises an employee (HRMS emp_supervisors): one active at a time, history kept. */
class EmpSupervisor extends Model
{
    protected $fillable = ['employee_id', 'supervisor_id', 'from_date', 'to_date', 'status', 'reason', 'assigned_by'];

    protected $casts = ['from_date' => 'date', 'to_date' => 'date'];

    public function employee() { return $this->belongsTo(Employee::class)->withTrashed(); }
    public function supervisor() { return $this->belongsTo(Employee::class, 'supervisor_id')->withTrashed(); }
    public function assignedBy() { return $this->belongsTo(User::class, 'assigned_by'); }
}
