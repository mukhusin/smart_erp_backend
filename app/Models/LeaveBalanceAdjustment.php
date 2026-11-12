<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** HR's correction to a leave balance (e.g. the opening balance brought from HRMS). */
class LeaveBalanceAdjustment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'employee_id', 'leave_type_id', 'leave_year', 'days', 'reason', 'created_by'];

    protected $casts = ['days' => 'float', 'leave_year' => 'integer'];

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
