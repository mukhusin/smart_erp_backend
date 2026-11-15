<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class HeadOfDepartment extends Model
{
    use Auditable;
    public const DEPARTMENT = 'hod';

    public const SECTION = 'hos';

    protected $fillable = ['employee_id', 'department_id', 'level', 'is_deputy', 'started_at', 'ended_at', 'change_reason', 'appointed_by', 'is_active'];

    protected $casts = ['is_deputy' => 'boolean', 'is_active' => 'boolean', 'started_at' => 'date', 'ended_at' => 'date'];

    public function employee() { return $this->belongsTo(Employee::class)->withTrashed(); }
    public function department() { return $this->belongsTo(Department::class); }
    public function appointedBy() { return $this->belongsTo(User::class, 'appointed_by'); }
}
