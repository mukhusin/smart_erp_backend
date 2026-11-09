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
        'description', 'attached_file_name', 'created_by', 'updated_by', 'status', 'is_active',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'hr_approved' => 'boolean', 'finance_approved' => 'boolean', 'director_approved' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function employee()         { return $this->belongsTo(Employee::class); }
    public function contract()         { return $this->belongsTo(EmployeeContract::class); }
    public function hrApprover()       { return $this->belongsTo(Employee::class, 'hr_approved_by'); }
    public function financeApprover()  { return $this->belongsTo(Employee::class, 'finance_approved_by'); }
    public function directorApprover() { return $this->belongsTo(Employee::class, 'director_approved_by'); }
    public function createdBy()        { return $this->belongsTo(Employee::class, 'created_by'); }
}
