<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Workflow\Models\WorkflowInstance;

/** A loan repaid from salary: an instalment each payroll until nothing remains (HRMS employee loans). */
class EmployeeLoan extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const STATUS_LABELS = [
        'pending' => 'Waiting for approval', 'enabled' => 'Repaying', 'paused' => 'Paused', 'disabled' => 'Repaid', 'rejected' => 'Not approved', 'cancelled' => 'Cancelled',
    ];

    protected $fillable = [
        'company_id', 'employee_id', 'loan_provider_id', 'updated_by',
        'date_of_loan', 'amount', 'instalment', 'returned', 'remain',
        'end_date', 'is_active', 'status', 'description', 'requested_by', 'decided_at', 'decision_comment',
    ];

    protected $casts = [
        'is_active' => 'boolean', 'date_of_loan' => 'date', 'end_date' => 'date', 'decided_at' => 'datetime',
        'amount' => 'float', 'instalment' => 'float', 'returned' => 'float', 'remain' => 'float',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function loanProvider()
    {
        return $this->belongsTo(LoanProvider::class)->withTrashed();
    }

    public function updatedBy()
    {
        return $this->belongsTo(Employee::class, 'updated_by');
    }

    public function histories()
    {
        return $this->hasMany(LoanHistory::class);
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }
}
