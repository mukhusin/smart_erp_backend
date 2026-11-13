<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Workflow\Models\WorkflowInstance;

/** A salary advance, deducted in full on the next payroll once approved. */
class EmployeeMonthlyAdvance extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const STATUS_LABELS = ['pending' => 'Waiting for approval', 'approved' => 'To be deducted', 'deducted' => 'Deducted', 'rejected' => 'Not approved', 'cancelled' => 'Cancelled'];

    protected $fillable = [
        'company_id', 'employee_id', 'created_by',
        'amount', 'reason', 'date_advanced', 'month', 'year', 'status', 'is_active', 'payroll_sheet_detail_id',
        'source', 'requested_by', 'decided_at', 'decision_comment',
    ];

    protected $casts = ['is_active' => 'boolean', 'date_advanced' => 'date', 'amount' => 'float', 'decided_at' => 'datetime'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function detail()
    {
        return $this->belongsTo(PayrollSheetDetail::class, 'payroll_sheet_detail_id');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
