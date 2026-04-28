<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppraisalKpiScore extends Model
{
    protected $fillable = [
        'employee_appraisal_id', 'appraisal_kpi_id',
        'self_score', 'self_comment', 'agreed_score', 'agreed_comment',
    ];

    public function employeeAppraisal() { return $this->belongsTo(EmployeeAppraisal::class); }
    public function kpi()               { return $this->belongsTo(AppraisalKpi::class, 'appraisal_kpi_id'); }
}
