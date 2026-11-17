<?php

namespace Modules\Assets\Models;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AssetAssignment extends Model
{
    protected $fillable = ['asset_id', 'department_id', 'employee_id', 'location', 'from_date', 'to_date', 'condition_out', 'condition_in', 'reason', 'returned_from', 'assigned_by', 'received_by'];

    protected $casts = ['from_date' => 'date', 'to_date' => 'date'];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
