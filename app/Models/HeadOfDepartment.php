<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HeadOfDepartment extends Model
{
    public const DEPARTMENT = 'hod';

    public const SECTION = 'hos';

    protected $fillable = ['employee_id', 'department_id', 'level', 'started_at', 'ended_at', 'change_reason', 'appointed_by', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'started_at' => 'date', 'ended_at' => 'date'];

    public function employee() { return $this->belongsTo(Employee::class)->withTrashed(); }
    public function department() { return $this->belongsTo(Department::class); }
    public function appointedBy() { return $this->belongsTo(User::class, 'appointed_by'); }
}
