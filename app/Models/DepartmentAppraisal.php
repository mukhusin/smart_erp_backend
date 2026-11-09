<?php
namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DepartmentAppraisal extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id','department_id','job_position_id','appraisal_title','description','is_active','created_by',
    ];

    protected $casts = ['is_active' => 'boolean'];
    public function department()  { return $this->belongsTo(Department::class); }
    public function jobPosition() { return $this->belongsTo(JobPosition::class); }
    public function kpis()        { return $this->hasMany(AppraisalKpi::class); }
    public function creator()     { return $this->belongsTo(User::class, 'created_by'); }
}
