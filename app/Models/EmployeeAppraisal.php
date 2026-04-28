<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeAppraisal extends Model
{
    protected $fillable = [
        'company_id', 'employee_id', 'department_appraisal_id', 'period',
        'status', 'employee_comment', 'hod_comment', 'total_score',
        'reviewed_by', 'reviewed_at',
    ];

    protected $casts = ['reviewed_at' => 'datetime', 'total_score' => 'decimal:2'];

    public function company()            { return $this->belongsTo(Company::class); }
    public function employee()           { return $this->belongsTo(Employee::class); }
    public function departmentAppraisal(){ return $this->belongsTo(DepartmentAppraisal::class); }
    public function reviewer()           { return $this->belongsTo(Employee::class, 'reviewed_by'); }
    public function scores()             { return $this->hasMany(AppraisalKpiScore::class); }

    public function computeTotalScore(): float
    {
        return $this->scores()
            ->whereNotNull('agreed_score')
            ->get()
            ->sum(fn($s) => ($s->agreed_score ?? 0) * ($s->kpi->weight ?? 0) / 100);
    }
}
