<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class AppraisalKpi extends Model
{
    protected $fillable = ['department_appraisal_id','kpi_title','description','weight'];

    protected $casts = ['weight' => 'decimal:2'];

    public function appraisal() { return $this->belongsTo(DepartmentAppraisal::class, 'department_appraisal_id'); }
}
