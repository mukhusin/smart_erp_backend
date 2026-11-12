<?php
namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model {
    use BelongsToCompany, SoftDeletes;
    protected $fillable = ['company_id','company_branch_id','parent_id','work_schedule_id','name','short_name','description','is_active'];
    protected $casts = ['is_active' => 'boolean'];
    public function branch() { return $this->belongsTo(CompanyBranch::class, 'company_branch_id'); }
    public function workSchedule() { return $this->belongsTo(WorkSchedule::class); }
    public function parent() { return $this->belongsTo(Department::class, 'parent_id'); }
    public function sections() { return $this->hasMany(Department::class, 'parent_id'); }
    public function employees() { return $this->hasMany(Employee::class); }
    public function positions() { return $this->hasMany(JobPosition::class); }
    public function sectionEmployees() { return $this->hasMany(Employee::class, 'section_id'); }
    public function heads() { return $this->hasMany(HeadOfDepartment::class)->latest('id'); }
    public function headOfDepartment() { return $this->hasOne(HeadOfDepartment::class)->where('is_active', true)->with('employee'); }
}
