<?php

namespace App\Models;


use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SalaryAdjustment extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'employee_id', 'contract_id', 'adjustment_type',
        'current_salary', 'percentage', 'amount', 'new_salary', 'reason', 'effective_date',
        'hr_approved', 'hr_approved_by', 'hr_approved_at',
        'finance_approved', 'finance_approved_by', 'finance_approved_at',
        'director_approved', 'director_approved_by', 'director_approved_at',
        'hr_comment', 'finance_comment', 'director_comment',
        'description', 'attached_file_name', 'created_by', 'updated_by', 'status', 'is_active', 'applied_at', 'requested_by',
    ];

    protected $casts = [
        'effective_date' => 'date', 'applied_at' => 'datetime',
        'current_salary' => 'float', 'new_salary' => 'float', 'amount' => 'float', 'percentage' => 'float',
        'hr_approved' => 'boolean', 'finance_approved' => 'boolean', 'director_approved' => 'boolean',
        'is_active' => 'boolean',
    ];

    public const REASONS = [
        'promotion' => 'Promotion', 'demotion' => 'Demotion', 'performance' => 'Performance', 'market' => 'Market adjustment',
        'cost_of_living' => 'Cost of living', 'correction' => 'Correction of an error', 'other' => 'Other',
    ];

    public function workflow()         { return $this->morphOne(\Modules\Workflow\Models\WorkflowInstance::class, 'subject')->latestOfMany(); }
    public function employee()         { return $this->belongsTo(Employee::class); }
    public function contract()         { return $this->belongsTo(EmployeeContract::class); }
    public function hrApprover()       { return $this->belongsTo(Employee::class, 'hr_approved_by'); }
    public function financeApprover()  { return $this->belongsTo(Employee::class, 'finance_approved_by'); }
    public function directorApprover() { return $this->belongsTo(Employee::class, 'director_approved_by'); }
    public function createdBy()        { return $this->belongsTo(Employee::class, 'created_by'); }
}
