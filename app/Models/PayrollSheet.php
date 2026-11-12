<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Models\WorkflowInstance;

/** A month's payroll: draft (worked out and changed), waiting for approval, approved (locked) or returned. */
class PayrollSheet extends Model
{
    use BelongsToCompany;

    public const STATUS_LABELS = ['draft' => 'Draft', 'pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Returned'];

    protected $fillable = [
        'company_id', 'name', 'month', 'year', 'payroll_from_date', 'payroll_to_date', 'number_of_days', 'payroll_cost',
        'hr_approved', 'payroll_approved', 'director_approved', 'status', 'hr_officer', 'payroll_officer', 'director', 'approved_by',
        'created_by', 'generated_at', 'submitted_at', 'approved_at', 'decision_comment',
        'employees_count', 'gross_total', 'deduction_total', 'net_total', 'employer_total',
    ];

    protected $casts = [
        'payroll_from_date' => 'date', 'payroll_to_date' => 'date', 'generated_at' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime',
        'gross_total' => 'float', 'deduction_total' => 'float', 'net_total' => 'float', 'employer_total' => 'float', 'payroll_cost' => 'float',
    ];

    public function details()
    {
        return $this->hasMany(PayrollSheetDetail::class);
    }

    public function comments()
    {
        return $this->hasMany(PayrollComment::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }

    public function editable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }
}
